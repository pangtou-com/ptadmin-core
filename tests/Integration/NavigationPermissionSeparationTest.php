<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class NavigationPermissionSeparationTest extends TestCase
{
    private string $root;
    private MenuSynchronizer $menus;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->root.'/storage');
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'ptadmin.addon.path' => $this->root.'/addons',
        ]);
        $directory = $this->root.'/storage/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-menu-children-'.bin2hex(random_bytes(6));
        parent::setUp();
        mkdir($this->root.'/resources', 0700, true);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->menus = new MenuSynchronizer(
            $this->app['db'], $this->app->make(AddonCatalog::class),
            $this->root.'/resources/menu.json', $this->root.'/addons', $this->root,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }


    public function testNavigationAndPermissionsHaveIndependentStorageAndLifecycle(): void
    {
        $this->write([$this->page()]);
        $result = $this->menus->syncApp($this->root);
        self::assertSame(1, $result['menus']['created']);
        self::assertSame(1, $result['permissions']['created']);
        $resource = DB::table('admin_resources')->where('resource_key', 'orders')->first();
        self::assertSame('permission', $resource->type);
        self::assertSame('', $resource->path);
        self::assertSame(0, $resource->is_menu);
        self::assertSame('/app/orders', DB::table('admin_menus')->value('path'));
        self::assertSame(2, $this->menus->syncApp($this->root)['unchanged']);
        $this->write([]);
        $this->menus->syncApp($this->root);
        self::assertSame(0, DB::table('admin_menus')->value('status'));
        self::assertSame(1, DB::table('admin_resources')->value('status'));
        self::assertSame($resource->id, DB::table('admin_resources')->value('id'));
    }

    public function testEntryPermissionControlsMenuButDoesNotConstrainOtherOperations(): void
    {
        $this->write([['resource_key' => 'sales', 'type' => 'group', 'children' => [$this->page()]]]);
        $this->menus->syncApp($this->root);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'operator', 'password' => 'unused', 'status' => true]);
        $auth = app(\PTAdmin\Admin\Auth\AuthorizationService::class);
        $this->grant($admin->id, ['delete']);
        self::assertTrue($auth->allows($admin, 'delete', 'orders'));
        self::assertFalse($auth->allows($admin, 'list', 'orders'));
        self::assertSame([], $auth->menus($admin));
        $this->grant($admin->id, ['list']);
        self::assertSame('sales', $auth->menus($admin)[0]['key']);
        self::assertSame('order.page', $auth->menus($admin)[0]['children'][0]['key']);
        $this->grant($admin->id, ['list'], 'deny');
        self::assertSame([], $auth->menus($admin));
        self::assertTrue($auth->allows($admin, 'delete', 'orders'));
        self::assertFalse($auth->allows($admin, 'access', 'orders'));
    }

    public function testSharedAndStandalonePermissionsDoNotCreateDuplicateResources(): void
    {
        $first = $this->page();
        $second = array_replace($first, ['resource_key' => 'order.review', 'path' => 'review', 'permissions' => [['ref' => 'orders']]]);
        $this->write([$first, $second, ['permissions' => [['resource_key' => 'jobs', 'operations' => [['key' => 'run']]]]]]);
        $this->menus->syncApp($this->root);
        self::assertSame(2, DB::table('admin_resources')->count());
        self::assertSame(2, DB::table('admin_menus')->count());
        self::assertSame(['orders'], json_decode(DB::table('admin_menus')->where('resource_key', 'order.review')->value('meta_json'), true)['permission_keys']);
    }

    public function testInvalidEntryFailsAtomicallyAndOldProtocolIsRejected(): void
    {
        $this->write([$this->page()]);
        $this->menus->syncApp($this->root);
        $before = DB::table('admin_menus')->get()->toJson();
        $this->write([array_replace($this->page(), ['title' => '不应保存', 'entry_permission' => 'orders.unknown'])]);
        try {
            $this->menus->syncApp($this->root);
            self::fail('未声明入口必须失败');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('入口权限未声明', $error->getMessage());
        }
        self::assertSame($before, DB::table('admin_menus')->get()->toJson());
        $this->write([['resource_key' => 'old', 'type' => 'action', 'abilities' => ['view']]]);
        $this->expectExceptionMessage('不再支持 action 或 abilities');
        $this->menus->syncApp($this->root);
    }

    public function testAddonDeactivationDisablesBothModelsWithoutDeletingGrants(): void
    {
        $this->write([$this->page()]);
        $this->menus->syncAddon($this->root, 'demo');
        $this->grant(10, ['list']);
        $this->menus->deactivateAddon('demo');
        self::assertSame(0, DB::table('admin_resources')->value('status'));
        self::assertSame(0, DB::table('admin_menus')->value('status'));
        self::assertSame(1, DB::table('admin_grants')->count());
        $this->menus->syncAddon($this->root, 'demo');
        self::assertSame(1, DB::table('admin_resources')->value('status'));
        self::assertSame(1, DB::table('admin_grants')->count());
    }

    public function testCatalogRequiresAuthorizationAndProjectsIndependentModels(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        app(MenuSynchronizer::class)->syncCore();
        $this->write([$this->page()]);
        $this->menus->syncApp($this->root);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'editor', 'password' => 'unused', 'status' => true]);
        $token = app(\PTAdmin\Core\Auth\Session\SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token'];
        $this->getJson('/ptadmin/authorization/catalog')->assertUnauthorized();
        $this->withToken($token)->getJson('/ptadmin/authorization/catalog')->assertForbidden();
        app(\PTAdmin\Core\Install\InitialAdministratorPermissions::class)->grant($admin);
        $resources = $this->withToken($token)->getJson('/ptadmin/authorization/catalog')->assertOk()->json('data.resources');
        $byKey = array_column($resources, null, 'resource_key');
        self::assertSame('permission', $byKey['orders']['type']);
        self::assertSame(['list', 'delete'], $byKey['orders']['abilities']);
        self::assertSame(['orders'], $byKey['__navigation__:order.page']['permission_keys']);
        self::assertSame([], $byKey['__navigation__:order.page']['abilities']);
        self::assertSame('orders.list', $byKey['__navigation__:order.page']['entry_permission']);
    }

    public function testDeniedParentPageIsOnlyAContainerForAuthorizedChildren(): void
    {
        $parent = $this->page();
        $parent['children'] = [[
            'resource_key' => 'order.child', 'title' => '子页面', 'type' => 'page', 'path' => 'child',
            'entry_permission' => 'children.view',
            'permissions' => [['resource_key' => 'children', 'operations' => [['key' => 'view']]]],
        ]];
        $this->write([$parent]);
        $this->menus->syncApp($this->root);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'reader', 'password' => 'unused', 'status' => true]);
        app(\PTAdmin\Core\Install\InitialAdministratorPermissions::class)->grant($admin);
        $this->grant($admin->id, ['list'], 'deny');
        $menu = app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin)[0];
        self::assertSame('group', $menu['type']);
        self::assertSame('', $menu['path']);
        self::assertSame('order.child', $menu['children'][0]['key']);
    }

    public function testNavigationCrudDoesNotWriteOrDeleteBusinessGrants(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        app(MenuSynchronizer::class)->syncCore();
        app(\PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer::class)->sync(app(\PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery::class)->discoverCore());
        $this->write([$this->page()]);
        $this->menus->syncApp($this->root);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'navigation-editor', 'password' => 'unused', 'status' => true]);
        app(\PTAdmin\Core\Install\InitialAdministratorPermissions::class)->grant($admin);
        $token = app(\PTAdmin\Core\Auth\Session\SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $before = DB::table('admin_grants')->get()->toJson();
        $url = '/ptadmin/schema/action/admin.admin_resources/records';
        $input = ['resource_key' => 'custom.orders', 'title' => '自定义订单入口', 'type' => 'page',
            'path' => '/custom-orders', 'is_menu' => true, 'status' => true, 'entry_permission' => 'orders.list', 'visibility' => 'permission'];
        $id = $this->postJson($url, ['data' => $input])->assertOk()->json('data.id');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'resource_key' => 'custom.orders']);
        $this->assertDatabaseMissing('admin_resources', ['resource_key' => 'custom.orders']);
        $this->putJson($url.'/'.$id, ['data' => ['title' => '改名后的订单']])->assertOk();
        $this->deleteJson($url.'/'.$id)->assertOk();
        self::assertSame($before, DB::table('admin_grants')->get()->toJson());
        self::assertTrue(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->allows($admin, 'list', 'orders'));
        $this->postJson($url, ['data' => array_replace($input, ['resource_key' => 'invalid.orders', 'entry_permission' => 'orders.missing'])])
            ->assertStatus(422)->assertJsonPath('data.code', 'invalid_entry_permission');
    }

    private function page(): array
    {
        return ['resource_key' => 'order.page', 'type' => 'page', 'title' => '订单', 'path' => 'orders',
            'entry_permission' => 'orders.list', 'permissions' => [['resource_key' => 'orders', 'title' => '订单操作',
                'operations' => [['key' => 'list', 'title' => '查看列表'], ['key' => 'delete', 'title' => '删除']], 'scopes' => ['all', 'self']]]];
    }

    private function write(array $definitions): void
    {
        file_put_contents($this->root.'/resources/menu.json', json_encode($definitions, JSON_THROW_ON_ERROR));
    }

    private function grant(int $admin, array $abilities, string $effect = 'allow'): void
    {
        DB::table('admin_grants')->insert(['subject_type' => 'admin', 'subject_id' => (string) $admin,
            'resource_id' => DB::table('admin_resources')->where('resource_key', 'orders')->value('id'),
            'effect' => $effect, 'abilities_json' => json_encode($abilities), 'scope_type' => 'self',
            'created_at' => now(), 'updated_at' => now()]);
    }
}
