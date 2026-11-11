<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\DataScopeBoundaryInterface;
use PTAdmin\Core\Auth\DataScopeRegistry;
use PTAdmin\Core\Auth\SchemaScopeResolver;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Install\InitialAdministratorPermissions;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Schema\Contract\ServerConstraint;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;

final class UnifiedAuthorizationTest extends TestCase
{
    private string $storage;
    private Admin $admin;
    private int $role;
    private int $resource;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-authorization-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->storage);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ptadmin.cloud.session_path', $this->storage.'/app/ptadmin/cloud/session.json');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('ptadmin.tasks.path', $this->storage.'/app/ptadmin/tasks');
        mkdir($this->storage.'/app/ptadmin/install', 0700, true);
        file_put_contents($this->storage.'/app/ptadmin/install/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.5.2', 'web_prefix' => 'console',
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->admin = Admin::query()->forceCreate(['username' => 'owner', 'password' => 'unused', 'status' => 1, 'is_founder' => true]);
        $this->role = DB::table('admin_roles')->insertGetId(['name' => '业务角色', 'code' => 'operator', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->resource = (int) DB::table('admin_resources')->where('resource_key', 'admin.users')->value('id');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testSessionSubjectIsClearedAfterSuccessFailureAndRejectedSession(): void
    {
        $context = app(\PTAdmin\Schema\Contracts\SchemaSubjectContextInterface::class);
        $middleware = app(\PTAdmin\Core\Http\Middleware\AuthenticateSessionMiddleware::class);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        foreach ([false, true] as $fail) {
            $request = \Illuminate\Http\Request::create('/');
            $request->headers->set('Authorization', 'Bearer '.$token);
            try {
                $middleware->handle($request, function () use ($context, $fail) {
                    self::assertSame($this->admin->id, $context->get()->id);
                    if ($fail) {
                        throw new \RuntimeException('request failed');
                    }
                    return response('ok');
                });
            } catch (\RuntimeException $exception) {
                self::assertTrue($fail);
                self::assertSame('request failed', $exception->getMessage());
            }
            self::assertNull($context->get());
        }
        $context->set($this->admin);
        self::assertSame(401, $middleware->handle(\Illuminate\Http\Request::create('/'), static fn () => response('unexpected'))->getStatusCode());
        self::assertNull($context->get());
    }

    public function testFounderIdentityHasNoPermissionsAndSnapshotDoesNotFollowFutureResources(): void
    {
        $auth = app(AuthorizationService::class);
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertSame([], $auth->permissions($this->admin));
        self::assertSame([], $auth->roles($this->admin));
        self::assertSame([], $auth->menus($this->admin));
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        self::assertTrue($auth->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertFalse($auth->allows($this->admin, 'future_action', 'admin.admin_users'));
        self::assertSame([], $auth->roles($this->admin));
        foreach (DB::table('admin_grants')->pluck('abilities_json') as $abilities) {
            self::assertNotContains('*', json_decode($abilities, true));
        }
        DB::table('admin_grants')->where('resource_id', $this->resource)->delete();
        app(InstallService::class)->syncAuthorizationResources();
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
        DB::table('admin_resources')->insert(['resource_key' => 'business.new', 'title' => '新业务', 'type' => 'action', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        self::assertFalse($auth->allows($this->admin, 'view', 'business.new'));
    }

    public function testAllowsAreMergedAndDenyAlwaysWinsRegardlessOfSourceOrPriority(): void
    {
        $this->bindRole();
        $auth = app(AuthorizationService::class);
        $this->grant('admin', ['list'], 'allow', 100);
        $roleAllow = $this->grant('role', ['list', 'update'], 'allow', 0);
        self::assertTrue($auth->allows($this->admin, 'update', 'admin.admin_users'));
        self::assertCount(2, $auth->decision($this->admin, 'list', 'admin.admin_users')->grants);
        $deny = $this->grant('role', ['list'], 'deny', 0);
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertNotContains('admin.users.list', $auth->permissions($this->admin));
        DB::table('admin_grants')->where('id', $deny)->delete();
        $deny = $this->grant('admin', ['update'], 'deny', 0);
        DB::table('admin_grants')->where('id', $roleAllow)->update(['priority' => 10000]);
        self::assertFalse($auth->allows($this->admin, 'update', 'admin.admin_users'));
        DB::table('admin_grants')->where('id', $deny)->update(['expires_at' => now()->subMinute()]);
        self::assertTrue($auth->allows($this->admin, 'update', 'admin.admin_users'));
        DB::table('admin_roles')->where('id', $this->role)->update(['status' => 0]);
        self::assertFalse($auth->allows($this->admin, 'update', 'admin.admin_users'));
        self::assertTrue($auth->allows($this->admin, 'list', 'admin.admin_users'));
    }

    public function testConditionsAliasesAndWildcardSessionExpansionRespectDenies(): void
    {
        $auth = app(AuthorizationService::class);
        $this->grant('admin', ['*'], 'allow', 999);
        $deny = $this->grant('admin', ['update'], 'deny', 0);
        DB::table('admin_grants')->where('id', $deny)->update(['conditions_json' => json_encode(['field' => 'admin_id', 'operator' => 'eq', 'value' => $this->admin->id + 1])]);
        self::assertTrue($auth->allows($this->admin, 'update', 'admin.admin_users'));
        DB::table('admin_grants')->where('id', $deny)->update(['conditions_json' => null]);
        self::assertFalse($auth->allows($this->admin, 'update', 'admin.admin_users'));
        $permissions = $auth->permissions($this->admin);
        self::assertNotContains('*', $permissions);
        self::assertNotContains('admin.users.*', $permissions);
        self::assertNotContains('admin.users.update', $permissions);
        self::assertContains('admin.users.list', $permissions);
        DB::table('admin_resources')->where('id', $this->resource)->update(['status' => 0]);
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
    }

    public function testRemovedAccessDoesNotExposeMenusOrBusinessOperations(): void
    {
        $this->grant('admin', ['access'], 'allow', 0);
        $auth = app(AuthorizationService::class);
        self::assertFalse($auth->allows($this->admin, 'access', 'admin.users'));
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertSame([], $auth->menus($this->admin));
        self::assertNotContains('admin.users.access', $auth->permissions($this->admin));
    }

    public function testEntryDenialHidesMenuWithoutDenyingOtherOperations(): void
    {
        $auth = app(AuthorizationService::class);
        $this->grant('admin', ['list', 'view'], 'allow', 0);
        self::assertNotEmpty($auth->menus($this->admin));
        $this->grant('admin', ['list'], 'deny', 0);
        self::assertSame([], $auth->menus($this->admin));
        self::assertTrue($auth->allows($this->admin, 'view', 'admin.users'));
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.users'));
    }

    public function testPersonalAndRoleScopesAreCombinedWithinMandatoryBoundary(): void
    {
        $this->schemas();
        $this->bindRole();
        $version = DB::table('schema_resources')->where('resource_key', 'admin.admin_users')->value('active_version_id');
        $schema = json_decode(DB::table('schema_resource_versions')->where('id', $version)->value('schema_json'), true);
        $schema['extensions']['authorization']['owner_field'] = 'id';
        DB::table('schema_resource_versions')->where('id', $version)->update(['schema_json' => json_encode($schema)]);
        $this->grant('admin', ['list'], 'allow', 100, 'self');
        $this->grant('role', ['list'], 'allow', 0, 'all');
        $boundary = new class implements DataScopeBoundaryInterface {
            public function resolve(Admin $admin, string $resourceKey, string $action, array $schema): ?ServerConstraint
            {
                return new ServerConstraint(['field' => 'id', 'operator' => 'in', 'value' => [1, 2]]);
            }
        };
        $resolver = new SchemaScopeResolver(app(AuthorizationService::class), DB::connection(), new DataScopeRegistry(), $boundary);
        self::assertSame(['field' => 'id', 'operator' => 'in', 'value' => [1, 2]], $resolver->resolve($this->admin, 'admin.admin_users', 'list')->toArray());
        $this->grant('role', ['list'], 'deny', 0);
        self::assertSame(['field' => 'id', 'operator' => 'in', 'value' => []], $resolver->resolve($this->admin, 'admin.admin_users', 'list')->toArray());
    }

    public function testPersonalPermissionsRoundTripAndRevocationUpdatesExistingSession(): void
    {
        $this->schemas();
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        $other = Admin::query()->forceCreate(['username' => 'operator', 'password' => 'unused', 'status' => 1]);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $before = $this->getJson('/ptadmin/session')->assertOk()->json('data.generation');
        foreach (['admin.login_logs', 'admin.operation_records', 'admin.notification_templates'] as $resource) {
            $this->getJson('/ptadmin/schema/action/'.$resource.'/records')->assertOk();
        }
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_roles', 'id' => $this->admin->id, 'formValues' => ['role_ids' => [$this->role]],
        ]])->assertOk()->assertJsonPath('data.role_ids', [$this->role]);
        DB::table('admin_user_roles')->where('admin_id', $this->admin->id)->delete();

        $permissions = [['resource_key' => 'admin.users', 'effect' => 'allow', 'abilities' => ['list'], 'scope_type' => 'all']];
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_permissions', 'id' => $other->id, 'formValues' => ['permissions' => $permissions],
        ]])->assertOk()->assertJsonPath('data.permissions.0.abilities', ['list']);
        self::assertTrue(app(AuthorizationService::class)->allows($other, 'list', 'admin.admin_users'));
        DB::table('admin_grants')->where('subject_type', 'admin')->where('subject_id', (string) $this->admin->id)->delete();
        $this->getJson('/ptadmin/session')->assertOk()->assertJsonPath('data.permissions', [])->assertJsonPath('data.menus', [])
            ->assertJsonPath('data.roles', [])->assertJsonMissing(['generation' => $before]);
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_permissions', 'id' => $other->id, 'formValues' => ['permissions' => []],
        ]])->assertForbidden();
    }

    public function testFounderCloudAccessDoesNotBypassOtherManagementPermissions(): void
    {
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token)->getJson('/ptadmin/cloud/session')->assertOk();
        $this->postJson('/ptadmin/system/updates/check')->assertForbidden();
        $this->postJson('/ptadmin/system/updates/install', ['component' => 'core', 'version' => '1.0.0'])->assertForbidden();
    }

    public function testPermissionActionsSaveAndRemovePersonalAndRoleDenials(): void
    {
        $this->schemas();
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        $other = Admin::query()->forceCreate(['username' => 'denial-target', 'password' => 'unused', 'status' => 1]);
        DB::table('admin_user_roles')->insert([
            'admin_id' => $other->id, 'role_id' => $this->role, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $allow = ['resource_key' => 'admin.users', 'effect' => 'allow', 'abilities' => ['list', 'update'], 'priority' => 0, 'scope_type' => 'all'];
        $deny = ['resource_key' => 'admin.users', 'effect' => 'deny', 'abilities' => ['update'], 'priority' => 0, 'scope_type' => 'all'];
        $save = function (string $resource, int $id, array $permissions): void {
            $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
                'resource' => $resource, 'action' => 'sync_permissions', 'id' => $id,
                'formValues' => ['permissions' => $permissions],
            ]])->assertOk();
        };
        $auth = app(AuthorizationService::class);
        $save('admin.admin_roles', $this->role, [$allow]);
        $save('admin.admin_users', $other->id, [$deny]);
        self::assertFalse($auth->allows($other, 'update', 'admin.admin_users'));
        self::assertTrue($auth->allows($other, 'list', 'admin.admin_users'));
        self::assertTrue($auth->allows($other, 'list', 'admin.users'));
        $save('admin.admin_users', $other->id, []);
        self::assertTrue($auth->allows($other, 'update', 'admin.admin_users'));
        $save('admin.admin_users', $other->id, [$allow]);
        $save('admin.admin_roles', $this->role, [$deny]);
        self::assertFalse($auth->allows($other, 'update', 'admin.admin_users'));
        self::assertTrue($auth->allows($other, 'list', 'admin.admin_users'));
        $save('admin.admin_roles', $this->role, []);
        self::assertTrue($auth->allows($other, 'update', 'admin.admin_users'));
    }

    public function testCloudMarketRequiresFounderEvenWithPersonalAndRoleGrants(): void
    {
        $this->admin->forceFill(['is_founder' => false])->save();
        $this->resource = DB::table('admin_resources')->insertGetId([
            'resource_key' => 'cloud.market', 'title' => '旧云市场', 'type' => 'action', 'status' => 1,
            'meta_json' => json_encode(['abilities' => ['manage']]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grant('admin', ['manage'], 'allow', 0);
        $this->grant('role', ['manage'], 'allow', 0);
        DB::table('admin_user_roles')->insert(['admin_id' => $this->admin->id, 'role_id' => $this->role, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Bus::fake();
        $tasks = app(\PTAdmin\Core\Tasks\TaskManager::class);
        $task = $tasks->create(\PTAdmin\Core\Tasks\TaskManager::CLOUD_PACKAGE);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        foreach (['session', 'session/authorize', 'catalog', 'developer/products'] as $path) {
            $this->getJson('/ptadmin/cloud/'.$path)->assertForbidden();
        }
        foreach (['session/logout', 'orders', 'delivery/options', 'delivery/package', 'licenses/preview'] as $path) {
            $this->postJson('/ptadmin/cloud/'.$path, [])->assertForbidden();
        }
        $path = '/ptadmin/tasks/'.$task['task_id'];
        $this->getJson($path)->assertForbidden();
        $this->getJson($path.'/events')->assertForbidden();
        $this->getJson($path.'/stream')->assertForbidden();
        $this->postJson($path.'/retry')->assertForbidden();
        $this->postJson($path.'/cancel')->assertForbidden();
        \Illuminate\Support\Facades\Bus::assertNothingDispatched();

        $this->admin->forceFill(['is_founder' => true])->save();
        DB::table('admin_grants')->delete();
        $this->getJson('/ptadmin/cloud/session')->assertOk();
        $this->getJson($path)->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\PTAdmin\Cloud\ExecuteCloudPackageTask::class);
        $this->getJson($path.'/events')->assertOk();
        $this->postJson($path.'/cancel')->assertOk();
    }

    public function testCloudResourceRetirementPreservesHistoryAndCannotBeRecreated(): void
    {
        $this->resource = DB::table('admin_resources')->insertGetId([
            'resource_key' => 'cloud.market', 'title' => '旧云市场', 'type' => 'action', 'status' => 1,
            'is_menu' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $grant = $this->grant('admin', ['manage'], 'allow', 0);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_140001_retire_cloud_market_authorization_resource.php';
        $migration->up();
        $deletedAt = DB::table('admin_resources')->where('id', $this->resource)->value('deleted_at');
        self::assertNotNull($deletedAt);
        self::assertTrue(DB::table('admin_grants')->where('id', $grant)->exists());
        $migration->up();
        self::assertSame($deletedAt, DB::table('admin_resources')->where('id', $this->resource)->value('deleted_at'));
        app(MenuSynchronizer::class)->syncCore();
        self::assertSame(0, DB::table('admin_resources')->where('resource_key', 'cloud.market')->whereNull('deleted_at')->count());
        $this->schemas();
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $results = $this->getJson('/ptadmin/schema/action/admin.admin_resources/records?limit=500')->assertOk()->json('data.results');
        self::assertNotContains('cloud.market', array_column($results, 'resource_key'));
        $permissions = $this->getJson('/ptadmin/schema/action/admin.admin_users/records/'.$this->admin->id)->assertOk()->json('data.item.permissions');
        self::assertNotContains('cloud.market', array_column($permissions, 'resource_key'));
        $this->postJson('/ptadmin/schema/action/admin.admin_resources/records', ['data' => [
            'resource_key' => 'cloud.market', 'title' => '云市场', 'type' => 'group', 'is_menu' => false, 'status' => true,
        ]])->assertStatus(422)->assertJsonPath('data.code', 'reserved_resource_key');
    }

    public function testAddonManagementRequiresFounderAndDoesNotUseLegacyGrants(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->admin->forceFill(['is_founder' => false])->save();
        foreach (\PTAdmin\Admin\Auth\FounderAccess::RESOURCE_KEYS as $key) {
            $this->resource = DB::table('admin_resources')->insertGetId([
                'resource_key' => $key, 'title' => $key, 'type' => 'action', 'status' => 1,
                'meta_json' => json_encode(['abilities' => ['view', 'create', 'update', 'delete']]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->grant('admin', ['*'], 'allow', 0);
            $this->grant('role', ['*'], 'allow', 0);
        }
        $this->bindRole();
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        foreach (['/ptadmin/addons', '/ptadmin/addons/demo/configuration', '/ptadmin/addons/demo/configuration/general'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->patchJson('/ptadmin/addons/demo/configuration/general', [])->assertForbidden();
        foreach (['download', 'demo/install', 'demo/update', 'demo/uninstall', 'demo/frontend-sync', 'demo/enable', 'demo/disable'] as $path) {
            $this->postJson('/ptadmin/addons/'.$path, [])->assertForbidden();
        }
        $tasks = app(\PTAdmin\Core\Tasks\TaskManager::class);
        $types = ['addon.download', 'addon.install', 'addon.update', 'addon.uninstall', 'addon.frontend_sync', 'addon.enable', 'addon.disable'];
        foreach ($types as $type) {
            $task = $tasks->create($type);
            $path = '/ptadmin/tasks/'.$task['task_id'];
            $this->postJson('/ptadmin/tasks', ['type' => $type])->assertForbidden();
            $this->getJson($path)->assertForbidden();
            $this->getJson($path.'/events')->assertForbidden();
            $this->getJson($path.'/stream')->assertForbidden();
            $this->postJson($path.'/retry')->assertForbidden();
            $this->postJson($path.'/cancel')->assertForbidden();
            $tasks->cancel($task['task_id']);
        }
        \Illuminate\Support\Facades\Bus::assertNothingDispatched();
        $this->resource = (int) DB::table('admin_resources')->where('resource_key', 'admin.config')->value('id');
        $this->grant('admin', ['view', 'update'], 'allow', 0);
        $this->getJson('/ptadmin/config/addon/demo/general')->assertForbidden();
        $this->putJson('/ptadmin/config/addon/demo/general', ['data' => []])->assertForbidden();
        $this->admin->forceFill(['is_founder' => true])->save();
        DB::table('admin_grants')->delete();
        $this->getJson('/ptadmin/addons')->assertOk();
        $this->getJson('/ptadmin/addons/demo/configuration')->assertOk();
        $this->postJson('/ptadmin/addons/download', [])->assertStatus(422)->assertJsonPath('data.error_code', 'addon_source_required');
        foreach ($types as $type) {
            $response = $this->postJson('/ptadmin/tasks', ['type' => $type])->assertCreated();
            $path = '/ptadmin/tasks/'.$response->json('data.task_id');
            $this->getJson($path)->assertOk();
            $this->getJson($path.'/events')->assertOk();
            $this->postJson($path.'/cancel')->assertOk();
        }
        $this->admin->forceFill(['is_founder' => false])->save();
        $this->getJson('/ptadmin/addons')->assertForbidden();
    }

    public function testAddonResourceRetirementKeepsHistoryAndBlocksRecreation(): void
    {
        $keys = array_values(array_diff(\PTAdmin\Admin\Auth\FounderAccess::RESOURCE_KEYS, ['cloud.market']));
        foreach ($keys as $key) {
            $this->resource = DB::table('admin_resources')->insertGetId([
                'resource_key' => $key, 'title' => $key, 'type' => 'action', 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->grant('admin', ['*'], 'allow', 0);
        }
        $grants = DB::table('admin_grants')->get();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_150001_retire_addon_management_authorization_resources.php';
        $migration->up();
        $retired = DB::table('admin_resources')->whereIn('resource_key', $keys)->get();
        $migration->up();
        app(MenuSynchronizer::class)->syncCore();
        self::assertEquals($retired, DB::table('admin_resources')->whereIn('resource_key', $keys)->get());
        self::assertEquals($grants, DB::table('admin_grants')->get());
        self::assertSame([], app(AuthorizationService::class)->permissions($this->admin));
        $this->schemas();
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $listed = array_column($this->getJson('/ptadmin/schema/action/admin.admin_resources/records?limit=500')->assertOk()->json('data.results'), 'resource_key');
        foreach ($keys as $key) {
            self::assertNotContains($key, $listed);
            $id = $retired->firstWhere('resource_key', $key)->id;
            $this->postJson('/ptadmin/schema/action/admin.admin_resources/records/'.$id.'/restore')->assertStatus(422)->assertJsonPath('data.code', 'action_not_available');
            $this->postJson('/ptadmin/schema/action/admin.admin_resources/records', ['data' => [
                'resource_key' => $key, 'title' => $key, 'type' => 'group', 'is_menu' => false, 'status' => true,
            ]])->assertStatus(422)->assertJsonPath('data.code', 'reserved_resource_key');
        }
    }

    public function testDirectResourceDenyOverridesAnAliasAllow(): void
    {
        $this->grant('admin', ['list'], 'allow', 10000);
        $resource = DB::table('admin_resources')->insertGetId([
            'resource_key' => 'admin.admin_users', 'title' => '直接资源', 'type' => 'schema', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $deny = $this->grant('admin', ['list'], 'deny', 0);
        DB::table('admin_grants')->where('id', $deny)->update(['resource_id' => $resource]);
        self::assertFalse(app(AuthorizationService::class)->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertFalse(app(AuthorizationService::class)->allows($this->admin, 'list', 'admin.users'));
        self::assertNotContains('admin.users.list', app(AuthorizationService::class)->permissions($this->admin));
    }

    public function testRemovedAliasAbilityCannotBorrowAnotherDeclarationOrWidenItsScope(): void
    {
        $this->bindRole();
        $this->grant('admin', ['*'], 'allow', 10000, 'all');
        $meta = json_decode(DB::table('admin_resources')->where('id', $this->resource)->value('meta_json'), true, 512, JSON_THROW_ON_ERROR);
        $meta['abilities'] = array_values(array_diff($meta['abilities'], ['list']));
        DB::table('admin_resources')->where('id', $this->resource)->update(['meta_json' => json_encode($meta, JSON_THROW_ON_ERROR)]);
        $aliasId = DB::table('admin_resources')->insertGetId([
            'resource_key' => 'staff.reader', 'title' => '人员读取', 'type' => 'permission', 'status' => true,
            'meta_json' => json_encode(['target_resource_key' => 'admin.admin_users', 'abilities' => ['list']], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $auth = app(AuthorizationService::class);
        self::assertFalse($auth->allows($this->admin, 'list', 'admin.admin_users'));
        self::assertFalse($auth->allows($this->admin, 'list', 'staff.reader'));

        $allow = $this->grant('role', ['list'], 'allow', 0, 'self');
        DB::table('admin_grants')->where('id', $allow)->update(['resource_id' => $aliasId]);
        $decision = $auth->decision($this->admin, 'list', 'admin.admin_users');
        self::assertTrue($decision->allowed);
        self::assertCount(1, $decision->grants);
        self::assertSame('self', $decision->grants[0]->scopeType);
        self::assertContains('staff.reader.list', $auth->permissions($this->admin));
        self::assertNotContains('admin.users.list', $auth->permissions($this->admin));

        // 旧声明上的明确拒绝仍约束同一目标，不能借能力移除解除拒绝。
        $deny = $this->grant('admin', ['list'], 'deny', 0);
        $before = DB::table('admin_grants')->orderBy('id')->get()->toJson();
        foreach (['admin.admin_users', 'admin.users', 'staff.reader'] as $resourceKey) {
            $denied = $auth->decision($this->admin, 'list', $resourceKey);
            self::assertFalse($denied->allowed);
            self::assertSame('explicit_deny', $denied->reason);
            self::assertSame($deny, $denied->grants[0]->id);
        }
        self::assertNotContains('staff.reader.list', $auth->permissions($this->admin));
        self::assertSame($before, DB::table('admin_grants')->orderBy('id')->get()->toJson());
    }

    public function testSessionPermissionEnumerationLoadsGrantsInBatches(): void
    {
        app(InitialAdministratorPermissions::class)->grant($this->admin);
        $auth = app(AuthorizationService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $permissions = $auth->permissions($this->admin);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertGreaterThan(30, count($permissions));
        self::assertLessThanOrEqual(3, count($queries));
    }

    public function testOrdinaryUpdateCannotBypassExplicitPermissionManagementDeny(): void
    {
        $this->schemas();
        $auth = app(AuthorizationService::class);
        $this->grant('admin', ['view', 'update'], 'allow', 0);
        $this->grant('admin', ['sync_permissions', 'sync_roles'], 'deny', 0);
        self::assertFalse($auth->allows($this->admin, 'manage', 'cloud.market'));
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        $permissions = [
            ['resource_key' => 'admin.users', 'effect' => 'allow', 'abilities' => ['view', 'update'], 'scope_type' => 'all'],
            ['resource_key' => 'cloud.market', 'effect' => 'allow', 'abilities' => ['manage'], 'scope_type' => 'all'],
        ];
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_permissions', 'id' => $this->admin->id,
            'formValues' => ['permissions' => $permissions],
        ]])->assertForbidden();
        $response = $this->putJson('/ptadmin/schema/action/admin.admin_users/records/'.$this->admin->id, [
            'data' => ['permissions' => $permissions],
        ]);
        $response->assertStatus(422)->assertJsonPath('data.code', 'invalid_permission_input');
        self::assertFalse($auth->allows($this->admin, 'manage', 'cloud.market'));
        self::assertFalse($auth->allows($this->admin, 'sync_permissions', 'admin.admin_users'));
    }

    public function testRoleAssignmentActionCannotWritePersonalPermissions(): void
    {
        $this->schemas();
        $auth = app(AuthorizationService::class);
        $this->grant('admin', ['view', 'sync_roles'], 'allow', 0);
        $this->grant('admin', ['sync_permissions'], 'deny', 0);
        self::assertFalse($auth->allows($this->admin, 'manage', 'cloud.market'));
        self::assertFalse($auth->allows($this->admin, 'sync_permissions', 'admin.admin_users'));
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token)->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_roles', 'id' => $this->admin->id,
            'formValues' => ['role_ids' => [], 'permissions' => [
                ['resource_key' => 'cloud.market', 'effect' => 'allow', 'abilities' => ['manage'], 'scope_type' => 'all'],
            ]],
        ]])->assertStatus(422)->assertJsonPath('data.code', 'invalid_permission_input');
        self::assertFalse($auth->allows($this->admin, 'manage', 'cloud.market'));
    }


    public function testOrdinaryWritesRejectAuthorizationFieldsWithoutPartialChanges(): void
    {
        $this->schemas();
        $this->grant('admin', ['create', 'update', 'view'], 'allow', 0);
        $this->grant('admin', ['sync_roles', 'sync_permissions'], 'deny', 0);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        foreach (['role_ids' => [$this->role], 'permissions' => []] as $field => $value) {
            $this->postJson('/ptadmin/schema/action/admin.admin_users/records', ['data' => [
                'username' => 'blocked-'.$field, 'nickname' => '不应创建', 'password' => 'test-password', $field => $value,
            ]])->assertStatus(422)->assertJsonPath('data.code', 'invalid_permission_input');
            self::assertFalse(Admin::query()->where('username', 'blocked-'.$field)->exists());
            $before = $this->admin->fresh()->nickname;
            $this->putJson('/ptadmin/schema/action/admin.admin_users/records/'.$this->admin->id, ['data' => [
                'nickname' => '不应保存', $field => $value,
            ]])->assertStatus(422)->assertJsonPath('data.code', 'invalid_permission_input');
            self::assertSame($before, $this->admin->fresh()->nickname);
            self::assertSame(0, DB::table('admin_user_roles')->where('admin_id', $this->admin->id)->count());
        }
        $this->postJson('/ptadmin/schema/action/admin.admin_users/records', ['data' => [
            'username' => 'plain-account', 'nickname' => '普通账号', 'password' => 'test-password',
        ]])->assertOk()->assertJsonPath('data.item.role_ids', [])->assertJsonPath('data.item.permissions', []);
    }

    public function testIndependentAuthorizationActionsRespectTargetScopeAndRejectCrossFields(): void
    {
        $this->schemas();
        $version = DB::table('schema_resources')->where('resource_key', 'admin.admin_users')->value('active_version_id');
        $schema = json_decode(DB::table('schema_resource_versions')->where('id', $version)->value('schema_json'), true);
        $schema['extensions']['authorization']['owner_field'] = 'id';
        $schema['fields'][] = ['name' => 'id', 'type' => 'integer', 'persisted' => true];
        DB::table('schema_resource_versions')->where('id', $version)->update(['schema_json' => json_encode($schema)]);
        $this->grant('admin', ['view', 'sync_roles', 'sync_permissions'], 'allow', 0, 'self');
        $other = Admin::query()->forceCreate(['username' => 'outside-scope', 'password' => 'unused', 'status' => 1]);
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->withToken($token);
        foreach (['sync_roles' => ['role_ids' => [$this->role]], 'sync_permissions' => ['permissions' => []]] as $action => $data) {
            $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
                'resource' => 'admin.admin_users', 'action' => $action, 'id' => $other->id, 'formValues' => $data,
            ]])->assertStatus(422)->assertJsonPath('data.code', 'record_not_found');
        }
        self::assertSame(0, DB::table('admin_user_roles')->where('admin_id', $other->id)->count());
        $before = DB::table('admin_grants')->count();
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_permissions', 'id' => $this->admin->id,
            'formValues' => ['permissions' => [], 'role_ids' => [$this->role]],
        ]])->assertStatus(422)->assertJsonPath('data.code', 'invalid_permission_input');
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_permissions', 'id' => $this->admin->id, 'formValues' => [],
        ]])->assertStatus(422);
        self::assertSame($before, DB::table('admin_grants')->count());
        $this->postJson('/ptadmin/schema/action', ['operation' => 'executeAction', 'context' => [
            'resource' => 'admin.admin_users', 'action' => 'sync_roles', 'id' => $this->admin->id,
            'formValues' => ['role_ids' => [$this->role]],
        ]])->assertOk()->assertJsonPath('data.role_ids', [$this->role]);
    }

    private function schemas(): void
    {
        app(SchemaFileSynchronizer::class)->sync(app(SchemaSourceDiscovery::class)->discoverCore());
    }

    private function bindRole(): void
    {
        DB::table('admin_user_roles')->insert(['admin_id' => $this->admin->id, 'role_id' => $this->role, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function grant(string $subject, array $abilities, string $effect, int $priority, string $scope = 'all'): int
    {
        return DB::table('admin_grants')->insertGetId([
            'subject_type' => $subject, 'subject_id' => (string) ($subject === 'admin' ? $this->admin->id : $this->role),
            'resource_id' => $this->resource, 'abilities_json' => json_encode($abilities), 'effect' => $effect,
            'priority' => $priority, 'scope_type' => $scope, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
