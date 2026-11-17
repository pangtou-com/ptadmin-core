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

    #[DataProvider('removedOperationGrants')]
    public function testRemovedOperationRejectsExistingGrantsWithoutDeletingHistory(string $subjectType, array $abilities): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('e', 32))]);
        $page = $this->page();
        $this->write([$page]);
        $this->menus->syncApp($this->root);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'operation-review', 'password' => 'unused', 'status' => true]);
        $subjectId = $admin->id;
        if ($subjectType === 'role') {
            $subjectId = DB::table('admin_roles')->insertGetId(['code' => 'operator', 'name' => '订单角色', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('admin_user_roles')->insert(['admin_id' => $admin->id, 'role_id' => $subjectId, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('admin_grants')->insert([
            'subject_type' => $subjectType, 'subject_id' => (string) $subjectId,
            'resource_id' => DB::table('admin_resources')->where('resource_key', 'orders')->value('id'),
            'effect' => 'allow', 'abilities_json' => json_encode($abilities, JSON_THROW_ON_ERROR), 'scope_type' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app['router']->get('/permission-probe/delete', static fn () => response()->json(['executed' => true]))
            ->middleware(['ptadmin.session', 'ptadmin.permission:orders,delete']);
        $this->withToken(app(\PTAdmin\Core\Auth\Session\SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token']);
        $this->getJson('/permission-probe/delete')->assertOk();
        $auth = app(\PTAdmin\Admin\Auth\AuthorizationService::class);
        self::assertContains('orders.delete', $auth->permissions($admin));
        $before = DB::table('admin_grants')->get()->toJson();

        $page['permissions'][0]['operations'] = [['key' => 'list']];
        $this->write([$page]);
        $this->menus->syncApp($this->root);

        self::assertSame($before, DB::table('admin_grants')->get()->toJson());
        self::assertNotContains('orders.delete', $auth->permissions($admin));
        $this->getJson('/permission-probe/delete')->assertForbidden();
        self::assertFalse($auth->allows($admin, 'delete', 'orders'));
        self::assertFalse($auth->allows($admin, 'future_action', 'orders'));
        self::assertFalse($auth->allows($admin, '*', 'orders'));
        self::assertTrue($auth->allows($admin, 'list', 'orders'));
        self::assertSame('order.page', $auth->menus($admin)[0]['key']);
    }

    public static function removedOperationGrants(): iterable
    {
        yield '个人具体授权' => ['admin', ['list', 'delete']];
        yield '角色具体授权' => ['role', ['list', 'delete']];
        yield '个人历史通配符' => ['admin', ['*']];
        yield '角色历史通配符' => ['role', ['*']];
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
        self::assertSame([], $byKey['__navigation__:admin']['permission_keys']);
        self::assertSame(['core.tasks'], $byKey['__navigation__:admin.tasks']['permission_keys']);
        self::assertFalse($byKey['__navigation__:admin.tasks']['is_menu']);
        self::assertSame('查看详情', $byKey['dashboard']['operation_labels']['view']['title']);
        self::assertSame('布局编辑', $byKey['admin.dashboard_widgets']['operation_labels']['update']['title']);
        self::assertSame('重置密码', $byKey['admin.users']['operation_labels']['reset_password']['title']);
        self::assertStringContainsString('撤销其现有登录会话', $byKey['admin.users']['operation_labels']['reset_password']['desc']);
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
            'path' => '/custom-orders', 'is_menu' => true, 'status' => true, 'entry_permission' => 'orders.list'];
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

    public function testCorePermissionRegroupingPreservesGrantsAndVisibleNavigation(): void
    {
        $definitions = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/menu.json'), true, 512, JSON_THROW_ON_ERROR);
        $groups = ['admin.tasks', 'admin.maintenance', 'admin.updates'];
        $previous = [];
        $permissions = [];
        foreach ($definitions as $definition) {
            if (in_array($definition['resource_key'], $groups, true)) {
                array_push($permissions, ...$definition['permissions']);
            } else {
                $previous[] = $definition;
            }
        }
        foreach ($previous as &$definition) {
            if ($definition['resource_key'] === 'admin') {
                $definition['permissions'] = $permissions;
            }
        }
        unset($definition);
        $this->write($previous);
        $this->menus->syncCore();
        $ids = DB::table('admin_resources')->orderBy('resource_key')->pluck('id', 'resource_key')->all();
        $visible = DB::table('admin_menus')->where('is_menu', true)->orderBy('id')->get()->toJson();
        DB::table('admin_grants')->insert([
            'subject_type' => 'admin', 'subject_id' => '10', 'resource_id' => $ids['core.tasks'],
            'effect' => 'allow', 'abilities_json' => '["view"]', 'scope_type' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $grants = DB::table('admin_grants')->get()->toJson();
        $this->write($definitions);
        $this->menus->syncCore();

        self::assertSame($ids, DB::table('admin_resources')->orderBy('resource_key')->pluck('id', 'resource_key')->all());
        self::assertSame($grants, DB::table('admin_grants')->get()->toJson());
        // 父目录移除关联权限，其他导航字段及可见菜单集合保持不变。
        $currentVisible = DB::table('admin_menus')->where('is_menu', true)->orderBy('id')->get();
        $previousVisible = json_decode($visible, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_column($previousVisible, 'resource_key'), $currentVisible->pluck('resource_key')->all());
        foreach ($groups as $key) {
            $this->assertDatabaseHas('admin_menus', ['resource_key' => $key, 'parent_key' => 'admin', 'is_menu' => false, 'type' => 'group']);
        }
        $meta = json_decode(DB::table('admin_menus')->where('resource_key', 'admin')->value('meta_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $meta['permission_keys']);
        self::assertSame(0, $this->menus->syncCore()['updated']);
    }

    #[DataProvider('navigationOwners')]
    public function testCodeNavigationProtectsBindingsButAllowsDisplayChanges(string $owner): void
    {
        $admin = $this->prepareNavigationEditor();
        $page = $this->page();
        $page['path'] = $owner === 'core' ? '/orders' : 'orders';
        $page['type'] = 'schema';
        $page['target_resource_key'] = 'admin.admin_users';
        $this->write([$page]);
        $this->syncNavigationOwner($owner);
        $this->grant($admin->id, ['list']);
        self::assertContains('order.page', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $id = DB::table('admin_menus')->where('resource_key', 'order.page')->value('id');
        $url = '/ptadmin/schema/action/admin.admin_resources/records/'.$id;
        $grants = DB::table('admin_grants')->get()->toJson();

        foreach ([
            'resource_key' => 'changed.page', 'type' => 'page',
            'target_resource_key' => 'admin.admin_roles', 'entry_permission' => 'orders.delete',
        ] as $field => $value) {
            $this->putJson($url, ['data' => [$field => $value]])->assertStatus(422)
                ->assertJsonPath('data.code', $field === 'resource_key' ? 'resource_key_immutable' : 'resource_binding_protected');
        }

        $this->putJson($url, ['data' => ['is_menu' => false]])->assertOk()->assertJsonPath('data.item.is_menu', false);
        self::assertNotContains('order.page', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $this->putJson($url, ['data' => ['is_menu' => true]])->assertOk()->assertJsonPath('data.item.is_menu', true);
        self::assertContains('order.page', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $this->putJson($url, ['data' => ['status' => false]])->assertOk()->assertJsonPath('data.item.status', false);
        self::assertNotContains('order.page', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $this->putJson($url, ['data' => ['status' => true]])->assertOk()->assertJsonPath('data.item.status', true);
        self::assertContains('order.page', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $this->putJson($url, ['data' => ['title' => '我的订单', 'icon' => 'folder', 'sort' => 70, 'path' => '/my-orders']])->assertOk();
        $this->putJson($url, ['data' => ['is_menu' => false, 'status' => false]])->assertOk();

        $page['title'] = '新版订单';
        $page['path'] = $owner === 'core' ? '/new-orders' : 'new-orders';
        $page['icon'] = 'settings';
        $page['sort'] = 99;
        $page['entry_permission'] = 'orders.delete';
        $page['target_resource_key'] = 'admin.admin_roles';
        $page['permissions'][0]['title'] = '新版订单权限';
        $this->write([$page]);
        $this->syncNavigationOwner($owner);
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'resource_key' => 'order.page', 'title' => '我的订单',
            'icon' => 'folder', 'sort' => 70, 'path' => '/my-orders', 'is_menu' => false, 'status' => false,
            'entry_permission' => 'orders.delete']);
        $meta = json_decode(DB::table('admin_menus')->where('id', $id)->value('meta_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('admin.admin_roles', $meta['target_resource_key']);
        self::assertArrayNotHasKey('entry_permission', $meta['navigation_overrides']);
        $this->assertDatabaseHas('admin_resources', ['resource_key' => 'orders', 'title' => '新版订单权限']);
        self::assertSame($grants, DB::table('admin_grants')->get()->toJson());
        self::assertSame(0, $this->syncNavigationOwner($owner)['updated']);
        $this->deleteJson($url)->assertStatus(422)
            ->assertJsonPath('data.code', $owner === 'core' ? 'core_resource_protected' : 'code_resource_protected');
    }

    public function testSchemaNavigationEntryPermissionMustBelongToSelectedTargetResource(): void
    {
        $this->prepareNavigationEditor();
        DB::table('admin_resources')->insert([
            'resource_key' => 'users', 'title' => '用户查看', 'type' => 'permission', 'meta_json' => json_encode([
                'owner_type' => 'custom', 'owner_code' => 'host', 'abilities' => ['view'], 'scopes' => ['all'],
                'target_resource_key' => 'admin.admin_users', 'operations' => ['view' => ['title' => '查看', 'desc' => '']],
            ]), 'module' => 'app', 'is_menu' => false, 'status' => true, 'sort' => 1,
            'parent_key' => '', 'path' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->write([array_replace($this->page(), [
            'resource_key' => 'users.page', 'type' => 'schema', 'target_resource_key' => 'admin.admin_users',
            'entry_permission' => 'users.view', 'permissions' => [['ref' => 'users']],
        ])]);
        $this->menus->syncApp($this->root);
        $this->assertDatabaseHas('admin_menus', ['resource_key' => 'users.page', 'entry_permission' => 'users.view']);
        $this->write([array_replace($this->page(), [
            'resource_key' => 'users.page', 'type' => 'schema', 'target_resource_key' => 'admin.admin_roles',
            'entry_permission' => 'users.view', 'permissions' => [['ref' => 'users']],
        ])]);
        $this->expectExceptionMessage('Schema 导航入口权限必须属于当前目标资源');
        $this->menus->syncApp($this->root);
    }

    public function testNavigationSyncKeepsManualMovementAndHiddenParent(): void
    {
        $this->prepareNavigationEditor();
        $page = $this->page();
        $this->write([
            ['resource_key' => 'order.group', 'type' => 'group', 'title' => '订单分组'],
            $page,
        ]);
        $this->menus->syncApp($this->root);
        $url = '/ptadmin/schema/action/admin.admin_resources/records/';
        $groupId = DB::table('admin_menus')->where('resource_key', 'order.group')->value('id');
        $pageId = DB::table('admin_menus')->where('resource_key', 'order.page')->value('id');
        $this->postJson($url.$pageId.'/action', ['action' => 'move_menu', 'data' => ['parent_key' => 'order.group']])->assertOk();
        $this->putJson($url.$groupId, ['data' => ['is_menu' => false]])->assertOk();
        $this->putJson($url.$pageId, ['data' => ['title' => '隐藏父级中的订单']])->assertOk();
        $this->menus->syncApp($this->root);
        $this->assertDatabaseHas('admin_menus', ['id' => $pageId, 'parent_key' => 'order.group', 'sort' => 10, 'title' => '隐藏父级中的订单']);
        $this->assertDatabaseHas('admin_menus', ['id' => $groupId, 'is_menu' => false]);
        self::assertSame(0, $this->menus->syncApp($this->root)['updated']);
    }

    public function testNavigationOverridesSurviveAddonLifecycleWithoutKeepingRemovedMenusActive(): void
    {
        $this->prepareNavigationEditor();
        $this->write([$this->page()]);
        $this->menus->syncAddon($this->root, 'demo');
        $id = DB::table('admin_menus')->where('resource_key', 'order.page')->value('id');
        $url = '/ptadmin/schema/action/admin.admin_resources/records/'.$id;
        $this->putJson($url, ['data' => ['is_menu' => false, 'status' => false]])->assertOk();
        $this->putJson($url, ['data' => ['status' => true]])->assertOk();
        $this->menus->deactivateAddon('demo');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'status' => false]);
        $this->menus->syncAddon($this->root, 'demo');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'status' => true, 'is_menu' => false]);
        $this->write([]);
        $this->menus->syncAddon($this->root, 'demo');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'status' => false]);
        $this->write([$this->page()]);
        $this->menus->syncAddon($this->root, 'demo');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'status' => true, 'is_menu' => false]);
    }

    public function testNavigationSyncUpdatesUntouchedFieldsAndPreservesEditedExternalLinks(): void
    {
        $this->prepareNavigationEditor();
        $external = ['resource_key' => 'external.page', 'type' => 'external', 'title' => '外链',
            'path' => '/external', 'external_url' => 'https://example.com/original'];
        $this->write([$external]);
        $this->menus->syncApp($this->root);
        $id = DB::table('admin_menus')->where('resource_key', 'external.page')->value('id');
        $this->putJson('/ptadmin/schema/action/admin.admin_resources/records/'.$id, [
            'data' => ['external_url' => 'https://example.com/custom', 'meta_json' => ['navigation_overrides' => ['entry_permission' => 'forged.read']]],
        ])->assertOk();
        $external['title'] = '新版外链';
        $external['external_url'] = 'https://example.com/new';
        $this->write([$external]);
        $this->menus->syncApp($this->root);
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'title' => '新版外链', 'entry_permission' => '']);
        $meta = json_decode(DB::table('admin_menus')->where('id', $id)->value('meta_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/custom', $meta['external_url']);
        self::assertSame(['external_url' => 'https://example.com/custom'], $meta['navigation_overrides']);
    }

    #[DataProvider('navigationOwners')]
    public function testExternalDefaultPermissionIsRoleAssignableAndDirectoriesFollowChildren(string $owner): void
    {
        $this->prepareNavigationEditor();
        $this->write([['resource_key' => 'help', 'title' => '帮助', 'type' => 'group', 'children' => [$this->externalMenu()]]]);
        $this->syncNavigationOwner($owner);
        self::assertSame(0, DB::table('admin_resources')->where('resource_key', 'help')->count());
        $permission = DB::table('admin_resources')->where('resource_key', 'help.docs')->first();
        self::assertNotNull($permission);
        $permissionMeta = json_decode($permission->meta_json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['view'], $permissionMeta['abilities']);
        self::assertSame(['all'], $permissionMeta['scopes']);
        self::assertSame(0, DB::table('admin_grants')->where('resource_id', $permission->id)->count());
        $catalog = $this->getJson('/ptadmin/authorization/catalog')->assertOk()->json('data.resources');
        $byKey = array_column($catalog, null, 'resource_key');
        self::assertSame(['help.docs'], $byKey['__navigation__:help.docs']['permission_keys']);
        self::assertSame('help.docs.view', $byKey['__navigation__:help.docs']['entry_permission']);
        self::assertSame('查看', $byKey['help.docs']['operation_labels']['view']['title']);
        $member = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'help-reader', 'password' => 'unused', 'status' => true]);
        $role = \PTAdmin\Admin\Models\AdminRole::query()->forceCreate(['code' => 'help-reader', 'name' => '文档读者', 'status' => true, 'is_system' => false]);
        $auth = app(\PTAdmin\Admin\Auth\AuthorizationService::class);
        self::assertSame([], $auth->menus($member));
        $roleUrl = '/ptadmin/schema/action/admin.admin_roles/records/'.$role->id.'/action';
        $this->postJson($roleUrl, ['action' => 'sync_permissions', 'data' => ['permissions' => [
            ['resource_key' => 'help.docs', 'abilities' => ['view'], 'effect' => 'allow', 'scope_type' => 'all'],
        ]]])->assertOk();
        $this->postJson('/ptadmin/schema/action/admin.admin_users/records/'.$member->id.'/action', [
            'action' => 'sync_roles', 'data' => ['role_ids' => [$role->id]],
        ])->assertOk();
        self::assertSame('help', $auth->menus($member)[0]['key']);
        self::assertSame('help.docs', $auth->menus($member)[0]['children'][0]['key']);
        self::assertContains('help.docs.view', $auth->permissions($member));
        $grants = DB::table('admin_grants')->get()->toJson();
        self::assertSame(0, $this->syncNavigationOwner($owner)['updated']);
        self::assertSame($grants, DB::table('admin_grants')->get()->toJson());
        $id = DB::table('admin_menus')->where('resource_key', 'help.docs')->value('id');
        $url = '/ptadmin/schema/action/admin.admin_resources/records/'.$id;
        foreach (['is_menu', 'status'] as $field) {
            $this->putJson($url, ['data' => [$field => false]])->assertOk();
            self::assertSame([], $auth->menus($member));
            $this->syncNavigationOwner($owner);
            self::assertSame([], $auth->menus($member));
            $this->putJson($url, ['data' => [$field => true]])->assertOk();
            self::assertSame('help', $auth->menus($member)[0]['key']);
        }
        $this->postJson($roleUrl, ['action' => 'sync_permissions', 'data' => ['permissions' => [
            ['resource_key' => 'help.docs', 'abilities' => ['view'], 'effect' => 'deny', 'scope_type' => 'all'],
        ]]])->assertOk();
        self::assertSame([], $auth->menus($member));
    }

    public function testCustomExternalNavigationRegistersPermissionAtomicallyWithoutGrantingIt(): void
    {
        $this->prepareNavigationEditor();
        $url = '/ptadmin/schema/action/admin.admin_resources/records';
        $input = $this->externalMenu();
        unset($input['path']);
        $grants = DB::table('admin_grants')->get()->toJson();
        $id = $this->postJson($url, ['data' => $input])->assertOk()->json('data.id');
        $this->assertDatabaseHas('admin_menus', ['id' => $id, 'path' => '']);
        $permission = DB::table('admin_resources')->where('resource_key', 'help.docs')->first();
        self::assertNotNull($permission);
        self::assertSame('custom', json_decode($permission->meta_json, true)['owner_type']);
        $this->putJson($url.'/'.$id, ['data' => ['title' => '文档入口', 'is_menu' => false]])->assertOk();
        self::assertSame(1, DB::table('admin_resources')->where('resource_key', 'help.docs')->count());
        $this->deleteJson($url.'/'.$id)->assertOk();
        self::assertSame($grants, DB::table('admin_grants')->get()->toJson());
        self::assertNotNull(DB::table('admin_resources')->find($permission->id));

        $this->postJson($url, ['data' => array_replace($input, ['resource_key' => 'bad.link', 'external_url' => 'javascript:alert(1)'])])->assertStatus(422);
        $this->assertDatabaseMissing('admin_resources', ['resource_key' => 'bad.link']);
        $this->postJson($url, ['data' => array_replace($input, ['resource_key' => 'core.tasks'])])->assertStatus(422)
            ->assertJsonPath('data.code', 'navigation_permission_conflict');
        $this->assertDatabaseMissing('admin_menus', ['resource_key' => 'core.tasks']);
        $this->postJson($url, ['data' => array_replace($input, ['resource_key' => 'public.link', 'visibility' => 'authenticated'])])->assertStatus(422);
        $this->assertDatabaseMissing('admin_resources', ['resource_key' => 'public.link']);
    }

    public function testExternalNavigationDoesNotStoreAnInternalPath(): void
    {
        $external = $this->externalMenu();
        unset($external['path']);
        $this->write([$external]);

        $this->menus->syncApp($this->root);

        $this->assertDatabaseHas('admin_menus', [
            'resource_key' => 'help.docs',
            'path' => '',
        ]);
    }

    public function testExplicitExternalEntryReusesPermissionAndMissingPageEntryIsRejected(): void
    {
        $this->write([$this->page(), array_replace($this->externalMenu(), [
            'entry_permission' => 'orders.list', 'permissions' => [['ref' => 'orders']],
        ])]);
        $this->menus->syncApp($this->root);
        $this->assertDatabaseMissing('admin_resources', ['resource_key' => 'help.docs']);
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'shared-entry', 'password' => 'unused', 'status' => true]);
        $this->grant($admin->id, ['list']);
        self::assertContains('help.docs', array_column(app(\PTAdmin\Admin\Auth\AuthorizationService::class)->menus($admin), 'key'));
        $this->write([array_replace($this->page(), ['entry_permission' => ''])]);
        $this->expectExceptionMessage('导航入口权限未声明');
        $this->menus->syncApp($this->root);
    }

    public function testExternalFallbackCannotOverwriteExistingBusinessPermission(): void
    {
        $this->write([$this->page()]);
        $this->menus->syncApp($this->root);
        $before = DB::table('admin_resources')->get()->toJson();
        $this->write([array_replace($this->externalMenu(), ['resource_key' => 'orders'])]);
        try {
            $this->menus->syncApp($this->root);
            self::fail('外链不得覆盖同名业务权限');
        } catch (\PTAdmin\Schema\Contract\SchemaError $error) {
            self::assertSame('navigation_permission_conflict', $error->getSchemaCode());
        }
        self::assertSame($before, DB::table('admin_resources')->get()->toJson());
        $this->assertDatabaseMissing('admin_menus', ['resource_key' => 'orders']);
        $this->write([array_replace($this->externalMenu(), ['visibility' => 'authenticated'])]);
        $this->expectExceptionMessage('不再支持 visibility');
        $this->menus->syncApp($this->root);
    }

    public function testVisibilityMigrationRegistersExistingExternalPermissionsWithoutGrants(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_100001_replace_navigation_visibility_with_external_permissions.php';
        $migration->down();
        $id = DB::table('admin_menus')->insertGetId([
            'resource_key' => 'legacy.docs', 'title' => '旧文档', 'type' => 'external', 'path' => '/docs',
            'visibility' => 'authenticated', 'entry_permission' => '', 'module' => 'app',
            'meta_json' => json_encode(['owner_type' => 'app', 'owner_code' => 'host', 'external_url' => 'https://example.com/docs']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('admin_menus', 'visibility'));
        $permission = DB::table('admin_resources')->where('resource_key', 'legacy.docs')->first();
        self::assertSame(['view'], json_decode($permission->meta_json, true)['abilities']);
        self::assertSame(['legacy.docs'], json_decode(DB::table('admin_menus')->where('id', $id)->value('meta_json'), true)['permission_keys']);
        self::assertSame(0, DB::table('admin_grants')->count());
        $migration->up();
        self::assertSame(1, DB::table('admin_resources')->where('resource_key', 'legacy.docs')->count());
        $migration->down();
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('admin_menus', 'visibility'));
        self::assertSame('permission', DB::table('admin_menus')->where('id', $id)->value('visibility'));
        self::assertNotNull(DB::table('admin_resources')->find($permission->id));
    }

    public function testVisibilityMigrationFailsBeforeOverwritingAnUnrelatedPermission(): void
    {
        $this->write([$this->page()]);
        $this->menus->syncApp($this->root);
        $before = DB::table('admin_resources')->get()->toJson();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_100001_replace_navigation_visibility_with_external_permissions.php';
        $migration->down();
        DB::table('admin_menus')->insert([
            'resource_key' => 'orders', 'title' => '冲突外链', 'type' => 'external', 'path' => '/external',
            'visibility' => 'authenticated', 'entry_permission' => '', 'meta_json' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $migration->up();
            self::fail('迁移不能接管已有业务权限');
        } catch (\PTAdmin\Schema\Contract\SchemaError $error) {
            self::assertSame('navigation_permission_conflict', $error->getSchemaCode());
        }
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('admin_menus', 'visibility'));
        self::assertSame($before, DB::table('admin_resources')->get()->toJson());
    }

    private function externalMenu(): array
    {
        return ['resource_key' => 'help.docs', 'title' => '帮助文档', 'type' => 'external',
            'path' => '/help-docs', 'external_url' => 'https://example.com/docs'];
    }

    public static function navigationOwners(): iterable
    {
        yield 'Core' => ['core'];
        yield 'Site' => ['app'];
        yield '插件' => ['addon'];
    }

    private function prepareNavigationEditor(): \PTAdmin\Admin\Models\Admin
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        app(MenuSynchronizer::class)->syncCore();
        app(\PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer::class)->sync(app(\PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery::class)->discoverCore());
        $admin = \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'navigation-editor', 'password' => 'unused', 'status' => true]);
        app(\PTAdmin\Core\Install\InitialAdministratorPermissions::class)->grant($admin);
        $this->withToken(app(\PTAdmin\Core\Auth\Session\SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token']);
        return $admin;
    }

    private function syncNavigationOwner(string $owner): array
    {
        return match ($owner) {
            'core' => $this->menus->syncCore(),
            'app' => $this->menus->syncApp($this->root),
            'addon' => $this->menus->syncAddon($this->root, 'demo'),
        };
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
