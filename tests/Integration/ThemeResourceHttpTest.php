<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{Artisan, DB};
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Theme\ThemeLifecycle;
use PTAdmin\Core\Theme\ActiveThemeResolver;

final class ThemeResourceHttpTest extends TestCase
{
    private string $storage;
    private array $headers;
    private int $resourceId;
    private Admin $admin;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pt_'],
            'ptadmin.addon.path' => $this->storage.'/addons',
            'ptadmin.site_theme.directory' => $this->storage.'/templates',
            'ptadmin.site_theme.public_directory' => $this->storage.'/public/themes',
            'ptadmin.site_theme.state_file' => $this->storage.'/theme.json',
            'ptadmin.api_prefix' => 'manage',
            'ptadmin.backend_version' => '0.12.1',
        ]);
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.4.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ]));
        $theme = $this->storage.'/templates/default@1.0.0';
        mkdir($theme.'/assets/css', 0700, true);
        file_put_contents($theme.'/theme.json', json_encode(['code' => 'default', 'version' => '1.0.0', 'name' => '默认主题']));
        file_put_contents($theme.'/assets/css/site.css', 'body {color: red}');
        file_put_contents($theme.'/index.blade.php', 'private template');
    }

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/theme-http-publication-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->resourceId = (int) DB::table('admin_resources')->where('resource_key', 'site.theme')->value('id');
        self::assertGreaterThan(0, $this->resourceId);
        $this->admin = Admin::query()->forceCreate(['username' => 'publisher', 'password' => 'unused', 'status' => true, 'is_founder' => false]);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Artisan::clearResolvedInstances();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testPublicationRequiresDedicatedPermissionAndRecordsAudit(): void
    {
        $url = '/manage/site/themes/default/1.0.0/publish';
        $this->postJson($url)->assertUnauthorized();
        $this->postJson($url, [], $this->headers)->assertForbidden();
        $this->grant(['read', 'activate']);
        $this->postJson($url, [], $this->headers)->assertForbidden();
        $this->grant(['publish']);
        $response = $this->postJson($url, [], $this->headers + ['X-Request-ID' => 'theme-publish-test']);
        $response->assertOk()->assertHeader('X-Request-ID', 'theme-publish-test')->assertJsonPath('data.published', true)
            ->assertJsonPath('data.code', 'default')->assertJsonPath('data.files', 1)->assertJsonPath('data.backup_id', null)
            ->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.task_id');
        self::assertSame('body {color: red}', file_get_contents($this->storage.'/public/themes/default@1.0.0/assets/css/site.css'));
        self::assertFileDoesNotExist($this->storage.'/public/themes/default@1.0.0/index.blade.php');
        $audit = DB::table('admin_operation_records')->where('request_id', 'theme-publish-test')->first();
        self::assertSame('site.theme', $audit->resource_key);
        self::assertSame('ptadmin.site.themes.publish', $audit->action);
        $this->grant([]);
        $this->postJson($url, [], $this->headers)->assertForbidden();
    }

    public function testIncompatibleThemeRemainsInspectableButCannotBeUsed(): void
    {
        $directory = $this->storage.'/templates/pangtou@1.0.0';
        mkdir($directory, 0700, true);
        $manifest = json_encode([
            'code' => 'pangtou', 'version' => '1.0.0', 'name' => '胖头主题',
            'description' => '线上主题', 'compatibility' => '>=0.9.0 <0.10.0', 'modules' => ['cms' => []],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/theme.json', $manifest);
        file_put_contents($directory.'/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAusAAAAASUVORK5CYII='));
        $state = json_encode(['code' => 'pangtou', 'version' => '1.0.0'], JSON_THROW_ON_ERROR);
        file_put_contents($this->storage.'/theme.json', $state);
        app(ActiveThemeResolver::class)->reset();

        $this->getJson('/manage/site/themes')->assertUnauthorized();
        $this->getJson('/manage/site/themes', $this->headers)->assertForbidden();
        $this->grant(['read', 'activate', 'publish']);
        $response = $this->getJson('/manage/site/themes', $this->headers)->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.results.0.state', 'installed')
            ->assertJsonPath('data.results.0.incompatibility_reason', null)
            ->assertJsonPath('data.results.1.code', 'pangtou')
            ->assertJsonPath('data.results.1.state', 'incompatible')
            ->assertJsonPath('data.results.1.active', false)
            ->assertJsonPath('data.results.1.description', '线上主题')
            ->assertJsonPath('data.results.1.compatibility', '>=0.9.0 <0.10.0')
            ->assertJsonPath('data.results.1.backend_version', '0.12.1');
        self::assertStringContainsString('当前 Core 0.12.1 不满足主题要求 >=0.9.0 <0.10.0', $response->json('data.results.1.incompatibility_reason'));
        $this->getJson('/manage/site/themes/pangtou/1.0.0', $this->headers)->assertOk()
            ->assertJsonPath('data.state', 'incompatible')->assertJsonPath('data.manifest.name', '胖头主题');
        $this->get($response->json('data.results.1.cover_url'))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->postJson('/manage/site/themes/pangtou/1.0.0/activate', [], $this->headers)->assertNotFound();
        $this->postJson('/manage/site/themes/pangtou/1.0.0/publish', [], $this->headers)->assertNotFound();
        self::assertNull(app(ActiveThemeResolver::class)->active());
        self::assertSame($state, file_get_contents($this->storage.'/theme.json'));
        self::assertSame($manifest, file_get_contents($directory.'/theme.json'));
        self::assertDirectoryDoesNotExist($this->storage.'/public/themes/pangtou@1.0.0');
    }

    public function testInvalidThemeMetadataRemainsADiagnosticAndCannotBeInspected(): void
    {
        $directory = $this->storage.'/templates/broken@1.0.0';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/theme.json', json_encode([
            'code' => 'broken', 'version' => '1.0.0', 'name' => '损坏主题', 'compatibility' => 'not-a-constraint',
        ]));
        $this->grant(['read']);
        $this->getJson('/manage/site/themes', $this->headers)->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.results.0.active', true)
            ->assertJsonPath('data.errors.0.identifier', 'broken@1.0.0')
            ->assertJsonPath('data.errors.0.error_code', 'invalid_theme_constraint');
        $this->getJson('/manage/site/themes/broken/1.0.0', $this->headers)->assertNotFound();
        $this->get('/manage/public/site/themes/broken/1.0.0/cover')->assertNotFound();
    }

    public function testCurrentThemeChangesAndBusyLockAreExplicitConflicts(): void
    {
        $this->grant(['publish']);
        $url = '/manage/site/themes/default/1.0.0/publish';
        $this->postJson('/manage/site/themes/missing/1.0.0/publish', [], $this->headers)->assertNotFound();
        $this->postJson($url, [], $this->headers)->assertOk();
        $lock = fopen($this->storage.'/app/ptadmin/themes/operations/lifecycle.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->postJson($url, [], $this->headers)->assertStatus(409)->assertJsonPath('data.error_code', 'theme_busy');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        file_put_contents($this->storage.'/theme.json', json_encode(['code' => 'other', 'version' => '1.0.0']));
        $this->postJson($url, [], $this->headers)->assertStatus(409)->assertJsonPath('data.error_code', 'theme_not_active');
    }

    public function testCommandConfirmsAndSupportsUnattendedDeploymentWithoutTouchingThemeState(): void
    {
        $this->artisan('ptadmin:theme:publish')->expectsConfirmation('将以主题源码替换公开资源并备份旧目录，是否继续？', 'no')->assertFailed();
        self::assertDirectoryDoesNotExist($this->storage.'/public/themes/default@1.0.0');
        self::assertSame(1, Artisan::call('ptadmin:theme:publish', ['--no-interaction' => true]));
        self::assertSame(0, Artisan::call('ptadmin:theme:publish', ['--force' => true, '--no-interaction' => true]), Artisan::output());
        self::assertFileDoesNotExist($this->storage.'/theme.json');
        file_put_contents($this->storage.'/templates/default@1.0.0/assets/css/site.css', 'updated');
        $this->artisan('ptadmin:theme:publish')->expectsConfirmation('将以主题源码替换公开资源并备份旧目录，是否继续？', 'yes')->assertSuccessful();
        self::assertSame('updated', file_get_contents($this->storage.'/public/themes/default@1.0.0/assets/css/site.css'));
        self::assertCount(1, glob($this->storage.'/app/ptadmin/themes/operations/backups/*'));
        file_put_contents($this->storage.'/theme.json', '{}');
        self::assertSame(1, Artisan::call('ptadmin:theme:publish', ['--force' => true]));
    }

    private function grant(array $abilities): void
    {
        DB::table('admin_grants')->where('subject_id', (string) $this->admin->id)->delete();
        if ($abilities !== []) {
            DB::table('admin_grants')->insert(['subject_type' => 'admin', 'subject_id' => (string) $this->admin->id,
                'resource_id' => $this->resourceId, 'effect' => 'allow', 'abilities_json' => json_encode($abilities),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
