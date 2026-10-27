<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Runtime\AddonRegistration;
use PTAdmin\Admin\Dashboard\{DashboardDataProviderInterface, DashboardService, DashboardWidgetRegistry, DashboardWidgetValidator};
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class DashboardAddonExtensionTest extends TestCase
{
    private string $storage;
    private Admin $admin;
    private DashboardAlphaProvider $alpha;
    private DashboardBetaProvider $beta;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-dashboard-addon-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->admin = Admin::query()->forceCreate(['username' => 'dashboard-owner', 'password' => 'unused', 'status' => 1, 'is_founder' => true]);
        $this->alpha = new DashboardAlphaProvider();
        $this->beta = new DashboardBetaProvider();
        $this->app->instance(DashboardAlphaProvider::class, $this->alpha);
        $this->app->instance(DashboardBetaProvider::class, $this->beta);
        $this->state('alpha', 'active');
        $this->state('beta', 'active');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testProvidersAreBatchedByOwnerAndCannotInjectOtherOwnersData(): void
    {
        $this->register('alpha', ['alpha.summary', 'alpha.second']);
        $this->register('beta', ['beta.summary']);
        $this->alpha->extra = ['beta.summary' => ['type' => 'stats', 'items' => [['label' => '伪造', 'value' => 999]]]];
        $results = $this->query(['alpha.summary', 'alpha.second', 'beta.summary', 'alpha.summary', 'unknown']);
        self::assertSame([['alpha.summary', 'alpha.second']], $this->alpha->calls);
        self::assertSame([['beta.summary']], $this->beta->calls);
        self::assertSame($this->admin->id, $results[2]['data']['items'][0]['value']);
        self::assertSame('widget_unavailable', $results[4]['error']['reason']);
        self::assertSame(['type' => 'addon', 'code' => 'alpha'], $results[0]['widget']['source']);
        self::assertStringNotContainsString('DashboardAlphaProvider', json_encode($results));
    }

    public function testProviderExecutionResolutionAndMalformedDataOnlyFailTheirOwnWidgets(): void
    {
        $this->register('alpha', ['alpha.summary', 'alpha.second']);
        $this->register('beta', ['beta.summary']);
        $this->alpha->fail = true;
        $results = $this->query(['alpha.summary', 'beta.summary', 'ptadmin.version-notices']);
        self::assertSame('provider_failed', $results[0]['error']['reason']);
        self::assertNull($results[1]['error']);
        self::assertNull($results[2]['error']);
        $this->alpha->fail = false;
        $this->alpha->extra = ['alpha.summary' => ['type' => 'stats', 'items' => [['label' => '无效', 'value' => INF]]]];
        $results = $this->query(['alpha.summary', 'alpha.second']);
        self::assertNull($results[0]['data']);
        self::assertSame('invalid_data', $results[0]['error']['reason']);
        self::assertNull($results[1]['error']);
        $this->app->forgetInstance(DashboardAlphaProvider::class);
        $this->app->bind(DashboardAlphaProvider::class, static function (): void { throw new \RuntimeException('测试容器解析失败'); });
        $results = $this->query(['alpha.summary', 'beta.summary']);
        self::assertSame('provider_failed', $results[0]['error']['reason']);
        self::assertNull($results[1]['error']);
    }

    public function testHostProviderStillOverridesCoreAndFallsBackWithoutReceivingPluginCodes(): void
    {
        $this->register('alpha', ['alpha.summary']);
        $host = new DashboardBetaProvider();
        $host->extra = [
            'ptadmin.workspace-summary' => ['type' => 'stats', 'items' => [['label' => '宿主指标', 'value' => 88]]],
            'alpha.summary' => ['type' => 'stats', 'items' => [['label' => '非法跨归属', 'value' => 999]]],
        ];
        $host->omit = ['ptadmin.version-notices'];
        $this->app->instance('dashboard.host.test', $host);
        config(['ptadmin-dashboard.provider' => 'dashboard.host.test']);
        $results = $this->query(['ptadmin.workspace-summary', 'ptadmin.version-notices', 'alpha.summary']);
        self::assertSame([['ptadmin.workspace-summary', 'ptadmin.version-notices']], $host->calls);
        self::assertSame(88, $results[0]['data']['items'][0]['value']);
        self::assertSame('list', $results[1]['data']['type']);
        self::assertSame($this->admin->id, $results[2]['data']['items'][0]['value']);
    }

    public function testRegistrationRequiresOwnerAndRollsBackWhenAnotherCapabilityCommitFails(): void
    {
        $registry = app(DashboardWidgetRegistry::class);
        try {
            $registry->registerAddon('alpha', $this->definitions('alpha', ['alpha.summary']), DashboardAlphaProvider::class);
            self::fail('不能在启动作用域外注册');
        } catch (\PTAdmin\Addon\Exception\AddonException $exception) {
            self::assertSame('capability_owner_invalid', $exception->errorCode);
        }
        $registration = app(AddonRegistration::class);
        try {
            $registration->run('alpha', function () use ($registry, $registration): void {
                $registry->registerAddon('alpha', $this->definitions('alpha', ['alpha.summary']), DashboardAlphaProvider::class);
                $registration->stage('alpha', 'later-capability', static function (): void { throw new \RuntimeException('测试后续能力提交失败'); }, static function (): void {});
            });
            self::fail('预期能力提交失败');
        } catch (\RuntimeException $exception) {
            self::assertSame('测试后续能力提交失败', $exception->getMessage());
        }
        self::assertArrayNotHasKey('alpha.summary', $registry->entries());
        $this->register('alpha', ['alpha.summary']);
        self::assertArrayHasKey('alpha.summary', $registry->entries());
    }

    public function testInvalidNamesPermissionsTypesAndHostConflictsAreRejectedAtomically(): void
    {
        foreach ([
            ['beta.summary' => ['title' => '越权', 'type' => 'stats', 'permission' => 'alpha.content.view']],
            ['alpha.summary' => ['title' => '缺权限', 'type' => 'stats']],
            ['alpha.summary' => ['title' => '未知类型', 'type' => 'html', 'permission' => 'alpha.content.view']],
            ['alpha.summary' => ['title' => '非法尺寸', 'type' => 'stats', 'permission' => 'alpha.content.view', 'default_layout' => ['w' => 50]]],
        ] as $definitions) {
            try {
                app(AddonRegistration::class)->run('alpha', fn () => app(DashboardWidgetRegistry::class)->registerAddon('alpha', $definitions, DashboardAlphaProvider::class));
                self::fail('预期非法定义失败');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('仪表盘', $exception->getMessage());
            }
            self::assertArrayNotHasKey('alpha.summary', app(DashboardWidgetRegistry::class)->entries());
        }
        config(['ptadmin-dashboard.widgets' => config('ptadmin-dashboard.widgets') + ['alpha.summary' => ['title' => '宿主同名', 'type' => 'stats']]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->register('alpha', ['alpha.summary']);
    }

    public function testInactiveAndDisposedWidgetsStayHiddenWhilePersonalPreferencesSurviveOtherSaves(): void
    {
        $this->register('alpha', ['alpha.summary']);
        $service = app(DashboardService::class);
        self::assertNotContains('alpha.summary', array_column($service->settings($this->admin)['selected_widgets'], 'code'));
        $service->saveSettings($this->admin, [['widget_code' => 'alpha.summary', 'enabled' => true, 'layout' => ['w' => 12], 'config' => ['color' => '#123456']]]);
        $before = DB::table('admin_dashboard_widgets')->where('widget_code', 'alpha.summary')->first();
        $this->state('alpha', 'inactive');
        self::assertNotContains('alpha.summary', array_column($service->settings($this->admin)['available_widgets'], 'code'));
        $service->saveSettings($this->admin, [['widget_code' => 'ptadmin.version-notices', 'enabled' => true]]);
        self::assertEquals($before, DB::table('admin_dashboard_widgets')->where('widget_code', 'alpha.summary')->first());
        self::assertSame('widget_unavailable', $this->query(['alpha.summary'])[0]['error']['reason']);
        self::assertSame([], $this->alpha->calls);
        $this->state('alpha', 'active');
        $selected = array_column($service->settings($this->admin)['selected_widgets'], null, 'code')['alpha.summary'];
        self::assertTrue($selected['enabled']);
        self::assertSame(12, $selected['layout']['w']);
        self::assertSame('#123456', $selected['config']['color']);
        $this->state('alpha', 'disposing');
        $this->state('alpha', 'disposed');
        self::assertSame('widget_unavailable', $this->query(['alpha.summary'])[0]['error']['reason']);
        self::assertEquals($before, DB::table('admin_dashboard_widgets')->where('widget_code', 'alpha.summary')->first());
    }

    public function testRealBearerPermissionRevocationPreventsQueriesAndStaleLayoutWrites(): void
    {
        $this->register('alpha', ['alpha.summary']);
        $this->admin->forceFill(['is_founder' => false])->save();
        $this->grant('dashboard', ['view', 'update']);
        $this->grant('admin.dashboard_widgets', ['update']);
        $headers = ['Authorization' => 'Bearer '.app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token']];
        $this->postJson('/ptadmin/dashboard/widgets/query', ['widgets' => [['code' => 'alpha.summary']]], $headers)
            ->assertOk()->assertJsonPath('data.results.0.error.reason', 'widget_unavailable');
        self::assertSame([], $this->alpha->calls);
        $this->grant('alpha.content', ['view']);
        $this->postJson('/ptadmin/dashboard/widgets/query', ['widgets' => [['code' => 'alpha.summary']]], $headers)
            ->assertOk()->assertJsonPath('data.results.0.data.items.0.value', $this->admin->id);
        $payload = ['widgets' => [['widget_code' => 'alpha.summary', 'enabled' => true]]];
        $this->putJson('/ptadmin/dashboard/me/widgets', $payload, $headers)->assertOk();
        DB::table('admin_grants')->where('resource_id', DB::table('admin_resources')->where('resource_key', 'alpha.content')->value('id'))->delete();
        $before = DB::table('admin_dashboard_widgets')->first();
        $this->putJson('/ptadmin/dashboard/me/widgets', $payload, $headers)->assertUnprocessable()->assertJsonPath('data.reason', 'widget_unavailable');
        self::assertEquals($before, DB::table('admin_dashboard_widgets')->first());
        $this->postJson('/ptadmin/dashboard/widgets/query', ['widgets' => [['code' => 'alpha.summary']]], $headers)
            ->assertOk()->assertJsonPath('data.results.0.data', null);
        self::assertCount(1, $this->alpha->calls);
        $this->postJson('/ptadmin/dashboard/widgets/query', ['widgets' => []])->assertUnauthorized();
    }

    public function testDisabledPolicyAndOverviewRequireAnAvailableStatsDefinition(): void
    {
        $this->register('alpha', ['alpha.summary']);
        config(['ptadmin-dashboard.overview_widget' => 'alpha.summary']);
        self::assertSame('alpha.summary', app(DashboardService::class)->dashboard($this->admin)['overview_widget']);
        config(['ptadmin-dashboard.disabled_widgets' => ['alpha.summary']]);
        self::assertSame('', app(DashboardService::class)->dashboard($this->admin)['overview_widget']);
        self::assertSame('widget_unavailable', $this->query(['alpha.summary'])[0]['error']['reason']);
        config(['ptadmin-dashboard.overview_widget' => 'ptadmin.version-notices']);
        self::assertSame('', app(DashboardService::class)->dashboard($this->admin)['overview_widget']);
    }

    public function testInvalidPreferenceBatchNeverPartiallyWritesAndKeepsSubjectsSeparate(): void
    {
        $this->register('alpha', ['alpha.summary']);
        $service = app(DashboardService::class);
        foreach ([
            ['widget_code' => 'alpha.summary', 'layout' => ['w' => 1, 'min_w' => 1]],
            ['widget_code' => 'alpha.summary', 'config' => ['color' => 'url(evil)']],
            ['widget_code' => ['alpha.summary']],
            ['widget_code' => 'alpha.summary', 'enabled' => 'false'],
        ] as $invalid) {
            try {
                $service->saveSettings($this->admin, [['widget_code' => 'ptadmin.version-notices', 'enabled' => true], $invalid]);
                self::fail('预期非法布局整批失败');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('仪表盘', $exception->getMessage());
            }
            self::assertSame(0, DB::table('admin_dashboard_widgets')->count());
        }
        $service->saveSettings($this->admin, [['widget_code' => 'alpha.summary', 'enabled' => true]]);
        $other = Admin::query()->forceCreate(['username' => 'second-dashboard-user', 'password' => 'unused', 'status' => 1, 'is_founder' => true]);
        self::assertNotContains('alpha.summary', array_column($service->settings($other)['selected_widgets'], 'code'));
        $result = $service->query($other, [['code' => 'alpha.summary']])['results'][0];
        self::assertSame($other->id, $result['data']['items'][0]['value']);
    }

    public function testDatasetProtocolRejectsUnsafeNavigationAndMismatchedTrendAndStripsPrivateFields(): void
    {
        $validator = app(DashboardWidgetValidator::class);
        $valid = [
            ['type' => 'stats', 'items' => [['label' => '统计', 'value' => 4, 'private' => 'secret']]],
            ['type' => 'trend', 'categories' => ['周一'], 'series' => [4], 'chart' => 'line'],
            ['type' => 'ranking', 'items' => [['id' => '1', 'label' => '排行', 'value' => '4']]],
            ['type' => 'list', 'items' => [['id' => '1', 'title' => '记录', 'status' => 'success']]],
            ['type' => 'card', 'items' => [['id' => '1', 'label' => '入口', 'target' => '/content?tab=recent']]],
        ];
        foreach ($valid as $dataset) {
            $result = $validator->dataset($dataset + ['private' => 'secret'], $dataset['type'], 'test.widget');
            self::assertStringNotContainsString('secret', json_encode($result));
        }
        foreach ([
            ['type' => 'trend', 'categories' => [], 'series' => [1]],
            ['type' => 'trend', 'categories' => ['一'], 'series' => ['1']],
            ['type' => 'stats', 'items' => [['label' => '错误', 'value' => []]]],
            ['type' => 'card', 'items' => [['id' => '1', 'label' => '错误', 'target' => '//evil.example']]],
            ['type' => 'card', 'items' => [['id' => '1', 'label' => '错误', 'target' => '/\\evil.example']]],
            ['type' => 'list', 'items' => array_fill(0, 101, ['id' => '1', 'title' => '过量'])],
        ] as $dataset) {
            try {
                $validator->dataset($dataset, $dataset['type'], 'test.widget');
                self::fail('预期数据协议校验失败');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('test.widget', $exception->getMessage());
            }
        }
    }

    public function testStatsAndListNavigationOnlyAcceptInternalTargets(): void
    {
        $validator = app(DashboardWidgetValidator::class);
        foreach (['stats' => ['label' => '待审核', 'value' => 4], 'list' => ['id' => '1', 'title' => '版本']] as $type => $item) {
            $result = $validator->dataset(['type' => $type, 'items' => [$item + ['target' => '/app/release-reviews?route=%2Frelease-reviews%2Faddon%2F1']]], $type, 'site.review');
            self::assertSame('/app/release-reviews?route=%2Frelease-reviews%2Faddon%2F1', $result['items'][0]['target']);
            foreach (['https://evil.example', '//evil.example', '/\\evil.example', '/bad path', '/bad'.chr(10).'path'] as $target) {
                try {
                    $validator->dataset(['type' => $type, 'items' => [$item + ['target' => $target]]], $type, 'site.review');
                    self::fail('非法导航地址必须被拒绝');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('site.review', $error->getMessage());
                }
            }
        }
    }

    public function testStatsComparisonIsOptionalValidatedAndProjectsOnlyPublicFields(): void
    {
        $validator = app(DashboardWidgetValidator::class);
        $dataset = ['type' => 'stats', 'items' => [['label' => '支付金额', 'value' => '12.00', 'comparison' => [
            'label' => '较昨日同时段', 'value' => '↑ 20.0%', 'tone' => 'success', 'private' => 'secret',
        ]]]];
        $result = $validator->dataset($dataset, 'stats', 'site.summary');
        self::assertSame(['label' => '较昨日同时段', 'value' => '↑ 20.0%', 'tone' => 'success'], $result['items'][0]['comparison']);
        foreach ([[], ['value' => '持平'], ['label' => '较昨日'], ['label' => '较昨日', 'value' => []],
            ['label' => '较昨日', 'value' => '持平', 'tone' => 'unknown'],
            ['label' => '较昨日', 'value' => str_repeat('a', 121)],
        ] as $comparison) {
            $dataset['items'][0]['comparison'] = $comparison;
            try {
                $validator->dataset($dataset, 'stats', 'site.summary');
                self::fail('非法对比应被拒绝');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('site.summary', $error->getMessage());
            }
        }
    }

    private function register(string $owner, array $codes): void
    {
        app(AddonRegistration::class)->run($owner, fn () => app(DashboardWidgetRegistry::class)->registerAddon(
            $owner, $this->definitions($owner, $codes), $owner === 'alpha' ? DashboardAlphaProvider::class : DashboardBetaProvider::class,
        ));
    }

    public function testCustomWidgetsUseOwnerBoundEntriesWithoutInvokingDataProviders(): void
    {
        $custom = ['title' => '专用内容', 'type' => 'custom', 'permission' => 'alpha.content.view', 'renderer' => ['entry' => 'alpha.dashboard']];
        app(AddonRegistration::class)->run('alpha', fn () => app(DashboardWidgetRegistry::class)->registerAddon(
            'alpha', ['alpha.custom' => $custom] + $this->definitions('alpha', ['alpha.summary']), DashboardAlphaProvider::class,
        ));
        $this->alpha->extra = ['alpha.custom' => ['type' => 'custom', 'renderer' => ['entry' => 'beta.page']]];
        $results = $this->query(['alpha.custom', 'alpha.summary']);
        self::assertSame([['alpha.summary']], $this->alpha->calls);
        self::assertSame(['type' => 'custom'], $results[0]['data']);
        self::assertSame(['entry' => 'alpha.dashboard'], $results[0]['widget']['renderer']);
        self::assertSame(['alpha' => ['alpha.dashboard' => ['alpha.custom']]], app(DashboardService::class)->frontendReferences($this->admin));
        app(AddonRegistration::class)->run('beta', fn () => app(DashboardWidgetRegistry::class)->registerAddon('beta', [
            'beta.custom' => array_replace($custom, ['permission' => 'beta.content.view', 'renderer' => ['entry' => 'beta.dashboard']]),
        ]));
        self::assertSame(['type' => 'custom'], $this->query(['beta.custom'])[0]['data']);
        $this->admin->forceFill(['is_founder' => false])->save();
        self::assertSame([], app(DashboardService::class)->frontendReferences($this->admin));
        self::assertSame('widget_unavailable', $this->query(['alpha.custom'])[0]['error']['reason']);
        $this->grant('alpha.content', ['view']);
        self::assertSame(['alpha' => ['alpha.dashboard' => ['alpha.custom']]], app(DashboardService::class)->frontendReferences($this->admin));
        config(['ptadmin-dashboard.disabled_widgets' => ['alpha.custom']]);
        self::assertSame([], app(DashboardService::class)->frontendReferences($this->admin));
        config(['ptadmin-dashboard.disabled_widgets' => []]);
        $this->state('alpha', 'inactive');
        self::assertSame([], app(DashboardService::class)->frontendReferences($this->admin));
    }

    public function testCustomRegistrationRejectsForeignUrlsMissingEntriesAndStandardWidgetsWithoutProviders(): void
    {
        foreach ([
            ['type' => 'custom'],
            ['type' => 'custom', 'renderer' => ['entry' => 'beta.page']],
            ['type' => 'custom', 'renderer' => ['entry' => 'https://evil.test/script.js']],
            ['type' => 'custom', 'renderer' => ['entry' => 'alpha.page', 'url' => 'evil']],
            ['type' => 'stats'],
        ] as $definition) {
            try {
                app(AddonRegistration::class)->run('alpha', fn () => app(DashboardWidgetRegistry::class)->registerAddon('alpha', [
                    'alpha.custom' => $definition + ['title' => '非法注册', 'permission' => 'alpha.content.view'],
                ]));
                self::fail('预期拒绝非法入口或缺失提供器');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('仪表盘', $exception->getMessage());
            }
            self::assertArrayNotHasKey('alpha.custom', app(DashboardWidgetRegistry::class)->entries());
        }
        $this->expectException(\InvalidArgumentException::class);
        app(DashboardWidgetValidator::class)->definition('host.custom', ['title' => '宿主自定义', 'type' => 'custom']);
    }

    private function definitions(string $owner, array $codes): array
    {
        return array_fill_keys($codes, ['title' => '插件统计', 'type' => 'stats', 'permission' => $owner.'.content.view']);
    }

    private function state(string $owner, string $state): void
    {
        $catalog = app(AddonCatalog::class);
        $lock = $catalog->beginOperation();
        try {
            app(LifecycleStore::class)->transition($owner, $state, ['manifest' => ['code' => $owner, 'version' => '1.0.0']]);
            if ($state !== 'disposing') {
                $catalog->publish($owner);
            }
        } finally {
            $catalog->endOperation($lock);
        }
    }

    private function query(array $codes): array
    {
        return app(DashboardService::class)->query($this->admin, array_map(static fn (string $code): array => ['code' => $code], $codes))['results'];
    }

    private function grant(string $key, array $abilities): void
    {
        $resource = DB::table('admin_resources')->where('resource_key', $key)->value('id');
        if ($resource === null) {
            $resource = DB::table('admin_resources')->insertGetId(['resource_key' => $key, 'title' => $key, 'type' => 'schema', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('admin_grants')->insert(['subject_type' => 'admin', 'subject_id' => (string) $this->admin->id, 'resource_id' => $resource, 'abilities_json' => json_encode($abilities), 'created_at' => now(), 'updated_at' => now()]);
    }
}

class DashboardAlphaProvider implements DashboardDataProviderInterface
{
    public array $calls = [];
    public array $extra = [];
    public array $omit = [];
    public bool $fail = false;

    public function query(Admin $admin, array $codes): array
    {
        $this->calls[] = $codes;
        if ($this->fail) {
            throw new \RuntimeException('测试插件数据失败');
        }
        return $this->extra + array_fill_keys(array_diff($codes, $this->omit), ['type' => 'stats', 'items' => [['label' => '当前管理员', 'value' => $admin->id]]]);
    }
}

final class DashboardBetaProvider extends DashboardAlphaProvider
{
}
