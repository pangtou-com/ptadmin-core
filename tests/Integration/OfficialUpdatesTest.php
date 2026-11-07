<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{Http, Hash};
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Updates\{InstalledVersions, OfficialCatalogVerifier, OfficialUpdateService, ReleaseVersion, UpdateEnvironment, UpdateException, UpdateStateStore};

final class OfficialUpdatesTest extends TestCase
{
    private string $directory;
    private array $versions;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-official-updates-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->directory);
        $app->usePublicPath($this->directory.'/public');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('ptadmin.addon.path', $this->directory.'/addons');
        mkdir($this->directory.'/app/ptadmin/install', 0700, true);
        file_put_contents($this->directory.'/app/ptadmin/install/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.0.1', 'web_prefix' => 'admin_test',
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['ptadmin-updates.path' => $this->directory.'/updates',
            'ptadmin-updates.composer_path' => $this->directory.'/missing-composer']);
        $this->versions = ['core' => ['version' => '1.0.0', 'source' => 'composer', 'known' => true],
            'admin-shell' => ['version' => '1.0.0', 'source' => 'deployed_manifest', 'known' => true, 'custom' => false, 'integrity' => 'verified', 'upstream_version' => null],
            'application-template' => ['version' => null, 'source' => 'recorded_origin', 'known' => false],
            'php' => '8.2.0', 'laravel' => '12.68.0', 'host_version' => null, 'api_protocol' => 1, 'schema_protocol' => 1];
        $fixture = new class extends InstalledVersions {
            public array $versions;
            public function read(): array { return $this->versions; }
        };
        $fixture->versions = &$this->versions;
        $this->app->instance(InstalledVersions::class, $fixture);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->directory);
    }

    private function release(string $version = '1.1.0', array $changes = []): array
    {
        return array_replace(['component' => 'core', 'version' => $version, 'status' => 'published',
            'compatibility' => ['php' => '^8.2', 'laravel' => '^12.68'], 'github_url' => 'https://github.com/ptadmin/core', 'gitee_url' => 'https://gitee.com/ptadmin/core',
            'changelog' => '修复问题', 'breaking_changes' => '', 'upgrade_steps' => '按锁文件部署', 'rollback_steps' => '恢复备份'], $changes);
    }

    private function document(): array
    {
        return ['protocol_version' => 1, 'generated_at' => gmdate(DATE_ATOM, time() - 10), 'expires_at' => gmdate(DATE_ATOM, time() + 3600),
            'results' => [$this->release(), $this->release('2.0.0', ['compatibility' => ['php' => '^8.4', 'laravel' => '^12.68']]), $this->release('3.0.0-rc.1')],
            'advisories' => [['identifier' => 'PT-2026-001', 'component' => 'core', 'severity' => 'critical', 'summary' => '需修复的安全问题',
                'affected_versions' => '<1.1.0', 'fixed_version' => '1.1.0', 'mitigation' => '暂停受影响入口', 'revision' => 1]]];
    }

    private function upstream(?array $document = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://cloud.api.pangtou.com/api/cloud/official/releases' => Http::response(['code' => 0, 'data' => ['document' => $document ?? $this->document()]])]);
    }

    private function state(array $changes): void
    {
        $store = app(UpdateStateStore::class);
        $store->locked(fn () => $store->write(array_replace($store->read(), $changes)));
    }

    public function testCatalogWithoutKeysSelectsCompatibleStableReleaseAndProvidesComposerFallback(): void
    {
        $this->upstream();
        app(OfficialUpdateService::class)->check();
        $result = app(OfficialUpdateService::class)->overview(1, true);
        self::assertSame('current', $result['check']['status'], json_encode($result['check']));
        self::assertSame('2.0.0', $result['components'][0]['latest']['version']);
        self::assertSame('1.1.0', $result['components'][0]['recommended']['version']);
        self::assertStringContainsString('PHP', $result['components'][0]['blockers'][0]);
        self::assertSame('https://gitee.com/ptadmin/core', $result['components'][0]['recommended']['gitee_url']);
        self::assertSame('composer_not_found', $result['core_guidance']['issues'][0]['code']);
        self::assertStringContainsString('vendor', implode(' ', $result['core_guidance']['steps']));
        self::assertStringContainsString('1.1.0', $result['core_guidance']['commands'][0]);
        self::assertFalse($result['environment']['online_core_update']);
        Http::assertSent(static fn ($request): bool => !$request->hasHeader('Authorization') && $request->data() === []);
    }

    public function testFailureKeepsTrustedWarningsAndReadDoesNotClearRisk(): void
    {
        $this->upstream();
        $service = app(OfficialUpdateService::class);
        $service->check();
        $service->acknowledge(1, 'PT-2026-001', 1);
        self::assertTrue($service->overview(1, true)['advisories'][0]['acknowledged']);
        self::assertFalse($service->overview(2, true)['advisories'][0]['acknowledged']);
        $this->state(['last_attempt_at' => 0]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('offline', 503)]);
        $service->check();
        $result = $service->overview(1, true);
        self::assertSame('failed', $result['check']['status']);
        self::assertTrue($result['check']['cached']);
        self::assertSame('affected', $result['advisories'][0]['match']);
        self::assertTrue($result['advisories'][0]['acknowledged']);
        $this->versions['core']['version'] = '1.1.0';
        self::assertSame([], $service->overview(1, true)['advisories']);
    }

    public function testRevisedAdvisoryBecomesUnreadAndWithdrawnFixIsNotRecommended(): void
    {
        $this->upstream();
        $service = app(OfficialUpdateService::class);
        $service->check();
        $service->acknowledge(1, 'PT-2026-001', 1);
        $document = $this->document();
        $document['advisories'][0]['revision'] = 2;
        $document['results'] = [];
        $this->state(['last_attempt_at' => 0]);
        $this->upstream($document);
        $service->check();
        $advisory = $service->overview(1, true)['advisories'][0];
        self::assertFalse($advisory['acknowledged']);
        self::assertFalse($advisory['fix_available']);
        $this->expectException(UpdateException::class);
        $service->acknowledge(1, 'PT-2026-001', 1);
    }

    public function testExpiredCacheRemainsExplicitlyHistoricalAndCannotBeAcceptedFromNetwork(): void
    {
        $document = $this->document();
        $document['generated_at'] = gmdate(DATE_ATOM, time() - 7200);
        $document['expires_at'] = gmdate(DATE_ATOM, time() - 60);
        $this->state(['envelope' => ['document' => $document]]);
        $result = app(OfficialUpdateService::class)->overview(1, true);
        self::assertSame('expired', $result['check']['status']);
        self::assertCount(1, $result['advisories']);
        $this->upstream($document);
        app(OfficialUpdateService::class)->check();
        self::assertSame('expired_catalog', app(OfficialUpdateService::class)->overview(1, true)['check']['error']['code']);
    }

    public function testVerifierRejectsMissingDocumentProtocolAndUnsafeLinks(): void
    {
        $protocol = $this->document();
        $protocol['protocol_version'] = 2;
        $unsafe = $this->document();
        $unsafe['results'][0]['github_url'] = 'javascript:alert(1)';
        $malformed = $this->document();
        $malformed['advisories'] = 'invalid';
        foreach ([[], ['document' => null], ['document' => $protocol], ['document' => $unsafe], ['document' => $malformed]] as $envelope) {
            try {
                app(OfficialCatalogVerifier::class)->verify($envelope);
                self::fail('应拒绝无效目录');
            } catch (UpdateException $exception) {
                self::assertContains($exception->reason, ['unsupported_protocol', 'invalid_catalog']);
            }
        }
    }

    public function testOldSignedCacheIsRefetchedWithoutLosingAcknowledgements(): void
    {
        $this->state(['envelope' => ['signed_payload' => base64_encode(json_encode($this->document())),
            'signature' => ['key_id' => 'retired']], 'last_attempt_at' => time(), 'next_check_at' => time() + 86400,
            'acknowledged' => ['1' => ['PT-2026-001' => 1]]]);
        $service = app(OfficialUpdateService::class);
        self::assertSame('never', $service->overview(1, true)['check']['status']);
        $this->upstream();
        $service->check(true);
        $result = $service->overview(1, true);
        self::assertSame('current', $result['check']['status']);
        self::assertTrue($result['advisories'][0]['acknowledged']);
        self::assertSame(['document'], array_keys(app(UpdateStateStore::class)->read()['envelope']));
        Http::assertSentCount(1);
    }

    public function testScheduleAndConcurrentCheckDoNotRepeatNetwork(): void
    {
        $this->upstream();
        $service = app(OfficialUpdateService::class);
        $service->check(true);
        $service->check(true);
        Http::assertSentCount(1);
        self::assertNotNull($service->overview(1, true)['check']['last_scheduled_at']);
        try {
            $service->check();
            self::fail('手动检查应冷却');
        } catch (UpdateException $exception) {
            self::assertSame('check_cooldown', $exception->reason);
        }
        $lock = fopen($this->directory.'/updates/check.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $service->check();
            self::fail('并发检查应拒绝');
        } catch (UpdateException $exception) {
            self::assertSame('check_running', $exception->reason);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testUnknownAndCustomVersionsRequireReviewInsteadOfClaimingSafe(): void
    {
        $document = $this->document();
        $document['advisories'][0]['component'] = 'admin-shell';
        $this->versions['admin-shell']['custom'] = true;
        $this->versions['admin-shell']['version'] = '9.0.0';
        $this->versions['admin-shell']['upstream_version'] = '1.1.0';
        $this->upstream($document);
        app(OfficialUpdateService::class)->check();
        $result = app(OfficialUpdateService::class)->overview(1, true);
        self::assertSame('custom', $result['components'][1]['comparison']);
        self::assertSame('review_required', $result['advisories'][0]['match']);
        self::assertSame('unknown', $result['components'][2]['comparison']);
    }

    public function testPermissionsAndProcessRestrictionsHaveActionableInstructions(): void
    {
        $environment = ['composer' => 'detected', 'process_execution' => false, 'writable' => ['vendor' => false, 'composer.lock' => false], 'delivery' => 'ci'];
        $guide = (new UpdateEnvironment())->guidance($environment, '1.2.0', false);
        self::assertSame(['founder_required', 'process_disabled', 'deployment_not_writable', 'managed_delivery'], array_column($guide['issues'], 'code'));
        self::assertStringContainsString('不要设置 777', $guide['issues'][2]['message']);
        self::assertStringContainsString('不能只复制', implode(' ', $guide['steps']));
    }

    public function testComposerPharDetectionDoesNotExecuteItsContents(): void
    {
        $path = $this->directory.'/composer.phar';
        file_put_contents($path, '<?php throw new RuntimeException("must not execute");');
        config(['ptadmin-updates.composer_path' => $path]);
        self::assertSame('detected', (new UpdateEnvironment())->inspect()['composer']);
        unlink($path);
        self::assertSame('not_found', (new UpdateEnvironment())->inspect()['composer']);
    }

    public function testCatalogRollbackAndMalformedCacheAreNotSilentlyAccepted(): void
    {
        $this->upstream();
        $service = app(OfficialUpdateService::class);
        $service->check();
        $document = $this->document();
        $document['generated_at'] = gmdate(DATE_ATOM, time() - 60);
        $this->state(['last_attempt_at' => 0]);
        $this->upstream($document);
        $service->check();
        self::assertSame('catalog_rollback', $service->overview(1, true)['check']['error']['code']);
        file_put_contents($this->directory.'/updates/state.json', '{broken');
        $result = $service->overview(1, true);
        self::assertSame('cache_unavailable', $result['check']['error']['code']);
        self::assertNull($result['components'][0]['recommended']);
    }

    public function testDisabledCheckKeepsReadOnlyGuidanceWithoutNetwork(): void
    {
        config(['ptadmin-updates.enabled' => false]);
        self::assertFalse(app(OfficialUpdateService::class)->overview(1, true)['check']['can_check']);
        try {
            app(OfficialUpdateService::class)->check();
            self::fail('已关闭联网检查');
        } catch (UpdateException $exception) {
            self::assertSame('checks_disabled', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function testHttpAuthorizationAndReadOnlyStatusNeverFetchUpstream(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Admin::query()->forceCreate(['username' => 'founder', 'password' => Hash::make('test-password'), 'status' => true, 'is_founder' => true]);
        Admin::query()->forceCreate(['username' => 'ordinary', 'password' => Hash::make('test-password'), 'status' => true, 'is_founder' => false]);
        $this->getJson('/ptadmin/system/updates')->assertUnauthorized();
        $token = $this->postJson('/ptadmin/login', ['username' => 'ordinary', 'password' => 'test-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->getJson('/ptadmin/system/updates')->assertOk()->assertJsonPath('data.check.can_check', false)->assertJsonPath('data.environment', null);
        $this->postJson('/ptadmin/system/updates/check')->assertForbidden();
        $this->postJson('/ptadmin/system/updates/advisories/PT-2026-001/acknowledge', ['revision' => 1])->assertForbidden();
        Http::assertNothingSent();
        $token = $this->postJson('/ptadmin/login', ['username' => 'founder', 'password' => 'test-password'])->assertOk()->json('data.access_token');
        $this->upstream();
        $this->withToken($token)->postJson('/ptadmin/system/updates/check')->assertOk()->assertJsonPath('data.check.status', 'current');
        $this->postJson('/ptadmin/system/updates/advisories/PT-2026-001/acknowledge', ['revision' => 1])->assertOk()->assertJsonPath('data.advisories.0.acknowledged', true);
        $this->postJson('/ptadmin/system/updates/advisories/PT-2026-001/acknowledge', ['revision' => '1'])->assertOk();
    }

    public function testInstalledVersionReaderUsesPublicAndDoesNotInferTemplateFromCore(): void
    {
        mkdir(public_path('admin_test'), 0700, true);
        file_put_contents(public_path('admin_test/manifest.json'), '{"frontend_version":"3.2.1-custom"}');
        $result = (new InstalledVersions())->read();
        self::assertSame('3.2.1-custom', $result['admin-shell']['version']);
        self::assertNull($result['application-template']['version']);
        self::assertNotSame('0.0.1', $result['core']['version']);
        unlink(public_path('admin_test/manifest.json'));
        self::assertNull((new InstalledVersions())->read()['admin-shell']['version']);
    }

    public function testNoInstallationOrCacheWritePermissionCannotTriggerNetwork(): void
    {
        config(['ptadmin-updates.path' => $this->directory.'/app/ptadmin/install/installed.json/updates']);
        try {
            app(OfficialUpdateService::class)->check();
            self::fail('文件路径不能当目录写入');
        } catch (UpdateException $exception) {
            self::assertSame('cache_not_writable', $exception->reason);
        }
        unlink($this->directory.'/app/ptadmin/install/installed.json');
        try {
            app(OfficialUpdateService::class)->check();
            self::fail('未安装不能检查');
        } catch (UpdateException $exception) {
            self::assertSame('not_installed', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function testSemverComparesNumericPrereleasesAndIgnoresBuildMetadata(): void
    {
        self::assertGreaterThan(0, ReleaseVersion::compare('1.10.0', '1.9.0'));
        self::assertGreaterThan(0, ReleaseVersion::compare('1.0.0-preview.10', '1.0.0-preview.2'));
        self::assertGreaterThan(0, ReleaseVersion::compare('1.0.0', '1.0.0-rc.1'));
        self::assertSame(0, ReleaseVersion::compare('1.0.0+first', '1.0.0+second'));
    }
}
