<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Dashboard\DashboardDataProviderInterface;
use PTAdmin\Admin\Dashboard\DashboardService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class DashboardWorkspaceTest extends TestCase
{
    private string $storage;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-dashboard-workspace-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->storage);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testCompositeVisibilityRequiresAnyPermissionAndRetainsSinglePermission(): void
    {
        config(['ptadmin-dashboard.widgets' => [
            'site.any' => ['title' => '复合概览', 'type' => 'stats', 'permissions_any' => ['site.orders.list', 'site.users.list']],
            'site.both' => ['title' => '双重约束', 'type' => 'stats', 'permission' => 'site.orders.detail', 'permissions_any' => ['site.orders.list']],
        ]]);
        $admin = $this->admin();
        $service = app(DashboardService::class);
        self::assertSame([], $service->settings($admin)['available_widgets']);
        $id = DB::table('admin_resources')->insertGetId(['resource_key' => 'site.orders', 'title' => '订单', 'type' => 'schema', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('admin_grants')->insertGetId([
            'subject_type' => 'admin', 'subject_id' => (string) $admin->id, 'resource_id' => $id,
            'abilities_json' => '["list"]', 'effect' => 'allow', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame(['site.any'], array_column($service->settings($admin)['available_widgets'], 'code'));
        DB::table('admin_grants')->where('id', $grant)->update(['abilities_json' => '["list","detail"]']);
        self::assertSame(['site.any', 'site.both'], array_column($service->settings($admin)['available_widgets'], 'code'));
        DB::table('admin_grants')->where('id', $grant)->delete();
        self::assertSame('widget_unavailable', $service->query($admin, [['code' => 'site.any']])['results'][0]['error']['reason']);
        $admin->forceFill(['is_founder' => true])->save();
        self::assertSame([], $service->settings($admin)['available_widgets']);
    }

    public function testCompositePermissionsRejectEmptyDuplicateAndObjectValues(): void
    {
        $validator = app(\PTAdmin\Admin\Dashboard\DashboardWidgetValidator::class);
        foreach ([[], ['site.orders.list', 'site.orders.list'], ['first' => 'site.orders.list'], ['site/orders']] as $permissions) {
            try {
                $validator->definition('site.summary', ['title' => '概览', 'type' => 'stats', 'permissions_any' => $permissions]);
                self::fail('非法权限条件应被拒绝');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('site.summary', $error->getMessage());
            }
        }
    }

    public function testSwitchableTrendValidatesEveryMetricAndProjectsPublicFields(): void
    {
        $validator = app(\PTAdmin\Admin\Dashboard\DashboardWidgetValidator::class);
        $data = ['type' => 'trend', 'categories' => ['今天'], 'series' => [1.25], 'unit' => '元', 'metrics' => [
            ['id' => 'amount', 'label' => '金额', 'series' => [1.25], 'unit' => '元', 'secret' => 'hidden'],
            ['id' => 'orders', 'label' => '订单', 'series' => [2], 'unit' => '笔'],
        ]];
        self::assertArrayNotHasKey('secret', $validator->dataset($data, 'trend', 'site.trend')['metrics'][0]);
        foreach ([[], ['bad' => $data['metrics'][0]], [$data['metrics'][0], $data['metrics'][0]],
            [$data['metrics'][0], ['id' => 'orders', 'label' => '订单', 'series' => ['2']]],
            [$data['metrics'][0], ['id' => 'orders', 'label' => '订单', 'series' => [INF]]],
            [['id' => 'amount', 'label' => '金额', 'series' => [9], 'unit' => '元']],
        ] as $metrics) {
            try {
                $validator->dataset(array_replace($data, ['metrics' => $metrics]), 'trend', 'site.trend');
                self::fail('非法切换指标应被拒绝');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('site.trend', $error->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $validator->definition('demo.widget', ['title' => '插件', 'type' => 'stats', 'permissions_any' => ['demo.list']], true);
    }

    public function testWelcomeAndMetricsArePersonalAndShortcutsOnlyUseAuthorizedMenus(): void
    {
        $admin = $this->admin();
        foreach ([['2026-10-25 10:00:00', true], ['2026-10-26 10:00:00', false], ['2026-10-27 10:00:00', true]] as [$date, $successful]) {
            DB::table('admin_login_logs')->insert(['admin_id' => $admin->id, 'username' => 'operator', 'successful' => $successful, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'created_at' => $date]);
        }
        $this->travelTo(now()->setDateTime(2026, 10, 27, 12, 0));
        $this->operation($admin->id, now());
        $this->operation($admin->id, now()->subDays(2));
        $this->operation($admin->id + 1, now());
        DB::table('admin_resources')->insert([
            ['resource_key' => 'private.page', 'title' => '私有功能', 'type' => 'page', 'path' => '/private', 'is_menu' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['resource_key' => 'disabled.page', 'title' => '停用功能', 'type' => 'page', 'path' => '/disabled', 'is_menu' => 1, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $service = app(DashboardService::class);
        $welcome = $service->dashboard($admin)['welcome'];
        self::assertSame(2, $welcome['login_count']);
        self::assertStringStartsWith('2026-10-25', $welcome['previous_login_at']);
        $results = $service->query($admin, [['code' => 'ptadmin.workspace-summary'], ['code' => 'ptadmin.quick-actions']])['results'];
        $metrics = array_column($results[0]['data']['items'], 'value', 'id');
        self::assertSame(1, $metrics['today_operations']);
        self::assertSame(2, $metrics['week_operations']);
        self::assertSame(0, $metrics['available_functions']);
        self::assertSame([], $results[1]['data']['items']);

        DB::table('admin_grants')->insert([
            'subject_type' => 'admin', 'subject_id' => (string) $admin->id,
            'resource_id' => DB::table('admin_resources')->where('resource_key', 'private.page')->value('id'),
            'abilities_json' => '["access"]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorized = $service->query($admin, [['code' => 'ptadmin.workspace-summary'], ['code' => 'ptadmin.quick-actions']])['results'];
        self::assertSame(1, array_column($authorized[0]['data']['items'], 'value', 'id')['available_functions']);
        self::assertSame(['private.page'], array_column($authorized[1]['data']['items'], 'id'));

        $admin->forceFill(['is_founder' => true])->save();
        config(['ptadmin-dashboard.shortcut_keys' => ['disabled.page', 'private.page', 'missing.page']]);
        $shortcuts = $service->query($admin, [['code' => 'ptadmin.quick-actions']])['results'][0]['data']['items'];
        self::assertSame(['private.page'], array_column($shortcuts, 'id'));
        self::assertSame('/private', $shortcuts[0]['target']);
    }

    public function testProviderReceivesOnlyAllowedUniqueCodesAndUnknownWidgetsRemainDenied(): void
    {
        $provider = new class implements DashboardDataProviderInterface {
            public array $received = [];
            public function query(Admin $admin, array $codes): array
            {
                $this->received = $codes;
                return ['site.sales' => ['type' => 'stats', 'items' => [['label' => '实收金额', 'value' => 599]]]];
            }
        };
        $this->app->instance('dashboard.test.provider', $provider);
        config(['ptadmin-dashboard.provider' => 'dashboard.test.provider', 'ptadmin-dashboard.widgets' => [
            'site.sales' => ['title' => '销售', 'type' => 'stats'],
            'site.private' => ['title' => '私有', 'type' => 'stats', 'permission' => 'site.private.access'],
        ]]);
        $results = app(DashboardService::class)->query($this->admin(), [
            ['code' => 'site.sales'], ['code' => 'site.private'], ['code' => 'unknown'], ['code' => 'site.sales'],
        ])['results'];
        self::assertSame(['site.sales'], $provider->received);
        self::assertSame(599, $results[0]['data']['items'][0]['value']);
        self::assertNull($results[1]['data']);
        self::assertNotNull($results[1]['error']);
        self::assertNull($results[2]['widget']);
    }

    public function testLayoutSaveKeepsSubjectsSeparateAndRestoresColor(): void
    {
        $service = app(DashboardService::class);
        $admin = $this->admin();
        $other = $this->admin('another');
        $widgets = [['widget_code' => 'ptadmin.recent-operations', 'enabled' => true, 'layout' => ['w' => 12], 'config' => ['color' => '#123456']]];
        $service->saveSettings($other, $widgets);
        $service->saveSettings($admin, $widgets);
        $widgets[0]['enabled'] = false;
        $service->saveSettings($admin, $widgets);
        self::assertTrue($service->settings($other)['selected_widgets'][0]['enabled']);
        self::assertFalse($service->settings($admin)['selected_widgets'][0]['enabled']);
        self::assertSame('#123456', $service->settings($admin)['selected_widgets'][0]['config']['color']);
        self::assertSame(2, DB::table('admin_dashboard_widgets')->count());
        config(['ptadmin-dashboard.editable' => false]);
        $this->expectException(\InvalidArgumentException::class);
        $service->saveSettings($admin, $widgets);
    }

    public function testTrendAggregatesByDayAndAnotherWidgetStillLoadsWhenOneFails(): void
    {
        $admin = $this->admin();
        $this->operation($admin->id, now());
        $this->operation($admin->id, now());
        $service = app(DashboardService::class);
        $result = $service->query($admin, [['code' => 'ptadmin.operation-trend']])['results'][0];
        self::assertSame(2, array_sum($result['data']['series']));
        self::assertCount(7, $result['data']['series']);
        DB::statement('DROP TABLE admin_operation_records');
        $results = $service->query($admin, [['code' => 'ptadmin.recent-operations'], ['code' => 'ptadmin.version-notices']])['results'];
        self::assertNotNull($results[0]['error']);
        self::assertNull($results[1]['error']);
        self::assertSame('list', $results[1]['data']['type']);
    }

    private function admin(string $username = 'operator'): Admin
    {
        return Admin::query()->forceCreate(['username' => $username, 'nickname' => '运营人员', 'status' => 1, 'password' => 'test-only-unused-hash']);
    }

    private function operation(int $adminId, \DateTimeInterface $date): void
    {
        DB::table('admin_operation_records')->insert([
            'admin_id' => $adminId, 'action' => '保存内容', 'method' => 'POST', 'path' => '/test',
            'status_code' => 200, 'successful' => true, 'created_at' => $date,
        ]);
    }
}
