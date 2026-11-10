<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;
use PTAdmin\Addon\Lifecycle\AddonUsagePermissions;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Cloud\CloudSessionManager;
use PTAdmin\Cloud\CloudPackageExecutor;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\ProductDeliveryClientInterface;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\Executors\AddonTaskExecutor;
use PTAdmin\Core\Tests\Support\SignedAddonPackage;
use RuntimeException;

final class AddonUsagePermissionsTest extends TestCase
{
    private string $root;
    private Admin $founder;
    private int $role;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-usage-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/storage/app/ptadmin/install', 0700, true);
        mkdir($this->root.'/public', 0700, true);
        file_put_contents($this->root.'/storage/app/ptadmin/install/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.7.0', 'web_prefix' => 'console',
        ]));
        $app->useStoragePath($this->root.'/storage');
        $app->usePublicPath($this->root.'/public');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('ptadmin.addon.path', $this->root.'/addons');
        $app['config']->set('ptadmin.addon.require_signature', false);
        $app['config']->set('ptadmin.addon.download_path', $this->root.'/staging');
        $app['config']->set('ptadmin.tasks.path', $this->root.'/tasks');
        $app['config']->set('ptadmin.cloud.session_path', $this->root.'/cloud-session.json');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->founder = Admin::query()->forceCreate(['username' => 'founder', 'password' => 'unused', 'is_founder' => true, 'status' => 1]);
        $this->role = DB::table('admin_roles')->insertGetId(['name' => '编辑', 'code' => 'editor', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        mkdir($this->root.'/package/resources', 0700, true);
        $this->package();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testDefaultInstallGrantsOnlyThisPluginAndRefreshExposesItsMenu(): void
    {
        $this->install();
        $auth = app(AuthorizationService::class);
        self::assertTrue($auth->allows($this->founder, 'list', 'usage.entries'));
        self::assertTrue($auth->allows($this->founder, 'delete', 'usage.entries'));
        self::assertFalse($auth->allows($this->founder, 'list', 'admin.users'));
        self::assertSame(0, DB::table('admin_user_roles')->count());
        self::assertSame(['founder' => true, 'role_ids' => []], app(AddonUsagePermissions::class)->options('usage-addon')['selection']);
        $this->login($this->founder);
        $this->getJson('/ptadmin/session')->assertOk()->assertJsonFragment(['key' => 'usage.entries']);
        self::assertSame('all', DB::table('admin_grants')->value('scope_type'));
        foreach (DB::table('admin_grants')->pluck('abilities_json') as $abilities) {
            self::assertNotContains('*', json_decode($abilities, true));
        }
    }

    public function testInstallationCountsSharedNavigationAndPermissionsSeparately(): void
    {
        $path = $this->root.'/package/resources/menu.json';
        $menus = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $menus[] = [
            'resource_key' => 'usage.review', 'title' => '审核入口', 'type' => 'page', 'path' => 'review',
            'entry_permission' => 'usage.entries.list', 'permissions' => [['ref' => 'usage.entries']],
        ];
        file_put_contents($path, json_encode($menus, JSON_THROW_ON_ERROR));

        $result = app(Installer::class)->install($this->root.'/package', 'console');
        self::assertSame(2, $result['resources']['admin_menus']);
        self::assertSame(1, $result['resources']['admin_resources']);
        self::assertSame(1, DB::table('admin_grants')->count());

        $grants = DB::table('admin_grants')->get()->toArray();
        $this->artisan('ptadmin:addon:setup', ['code' => 'usage-addon'])
            ->expectsOutputToContain('导航=2，权限资源=1')
            ->assertSuccessful();
        self::assertEquals($grants, DB::table('admin_grants')->get()->toArray());
    }

    public function testUpdateAddsNewAbilitiesWithoutRestoringRemovedOrChangingScopedAndDeniedGrants(): void
    {
        $selection = ['founder' => true, 'role_ids' => [$this->role]];
        $this->install($selection);
        $resource = DB::table('admin_resources')->where('resource_key', 'usage.entries')->value('id');
        DB::table('admin_grants')->where('subject_type', 'admin')->delete();
        DB::table('admin_grants')->where('subject_type', 'role')->update(['scope_type' => 'self']);
        $before = DB::table('admin_grants')->first();
        DB::table('admin_grants')->insert([
            'subject_type' => 'role', 'subject_id' => (string) $this->role, 'resource_id' => $resource,
            'abilities_json' => '["delete"]', 'effect' => 'deny', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->package(['list', 'view', 'delete', 'export'], true);
        $this->install(update: true);
        $auth = app(AuthorizationService::class);
        self::assertFalse($auth->allows($this->founder, 'list', 'usage.entries'));
        self::assertTrue($auth->allows($this->founder, 'export', 'usage.entries'));
        self::assertTrue($auth->allows($this->founder, 'list', 'usage.reports'));
        self::assertEquals($before, DB::table('admin_grants')->where('id', $before->id)->first());
        $member = Admin::query()->forceCreate(['username' => 'member', 'password' => 'unused', 'status' => 1]);
        DB::table('admin_user_roles')->insert(['admin_id' => $member->id, 'role_id' => $this->role, 'created_at' => now(), 'updated_at' => now()]);
        self::assertFalse($auth->allows($member, 'delete', 'usage.entries'));
        self::assertTrue($auth->allows($member, 'export', 'usage.entries'));
        $grants = DB::table('admin_grants')->orderBy('id')->get()->toArray();
        $this->install(update: true);
        self::assertEquals($grants, DB::table('admin_grants')->orderBy('id')->get()->toArray());
        self::assertSame($selection, app(AddonUsagePermissions::class)->options('usage-addon')['selection']);
    }

    public function testRoleOnlySelectionAndOptOutDoNotGrantFounderOrRevokeExistingRights(): void
    {
        $this->install(['founder' => false, 'role_ids' => [$this->role]]);
        self::assertSame(0, DB::table('admin_grants')->where('subject_type', 'admin')->count());
        $before = DB::table('admin_grants')->get()->toArray();
        $this->package(['list', 'view', 'delete', 'export']);
        $this->install(['founder' => false, 'role_ids' => []], true);
        self::assertEquals($before, DB::table('admin_grants')->get()->toArray());
        $this->install(update: true);
        self::assertEquals($before, DB::table('admin_grants')->get()->toArray());
        // 重新选择已有对象仍记得人工取消的旧能力，新选择的创始人获得初始快照。
        DB::table('admin_grants')->delete();
        $this->install(['founder' => true, 'role_ids' => [$this->role]], true);
        $roleAbilities = DB::table('admin_grants')->where('subject_type', 'role')->pluck('abilities_json')->all();
        self::assertSame(['["export"]'], $roleAbilities);
        self::assertTrue(app(AuthorizationService::class)->allows($this->founder, 'list', 'usage.entries'));
    }

    public function testFirstSelectionPreservesExistingManualScopeAndDenyAndSyncDoesNotGrant(): void
    {
        app(MenuSynchronizer::class)->syncAddon($this->root.'/package', 'usage-addon');
        self::assertSame(0, DB::table('admin_grants')->count());
        $resource = DB::table('admin_resources')->where('resource_key', 'usage.entries')->value('id');
        DB::table('admin_grants')->insert([
            ['subject_type' => 'admin', 'subject_id' => (string) $this->founder->id, 'resource_id' => $resource,
                'abilities_json' => '["list"]', 'effect' => 'allow', 'scope_type' => 'self', 'created_at' => now(), 'updated_at' => now()],
            ['subject_type' => 'admin', 'subject_id' => (string) $this->founder->id, 'resource_id' => $resource,
                'abilities_json' => '["delete"]', 'effect' => 'deny', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $before = DB::table('admin_grants')->orderBy('id')->get();
        $this->install();
        self::assertEquals($before, DB::table('admin_grants')->whereIn('id', $before->pluck('id'))->orderBy('id')->get());
        self::assertSame('["view"]', DB::table('admin_grants')->orderByDesc('id')->value('abilities_json'));
    }

    public function testFailureRollsBackGrantsAndPolicyAndRetryDoesNotDuplicate(): void
    {
        try {
            app(Installer::class)->install($this->root.'/package', 'console', onProgress: static function (string $stage): void {
                if ($stage === 'publishing') {
                    throw new RuntimeException('模拟授权后的发布失败');
                }
            });
            self::fail('Expected installation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('模拟授权后的发布失败', $exception->getMessage());
        }
        self::assertSame(0, DB::table('admin_grants')->count());
        self::assertNull(app(LifecycleStore::class)->read('usage-addon'));
        $this->install();
        $grants = DB::table('admin_grants')->get()->toArray();
        $state = app(LifecycleStore::class)->read('usage-addon');
        $this->package(['list', 'view', 'delete', 'export']);
        try {
            app(Installer::class)->install($this->root.'/package', 'console', true, onProgress: static function (string $stage): void {
                if ($stage === 'publishing') {
                    throw new RuntimeException('模拟更新失败');
                }
            });
            self::fail('Expected update failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('模拟更新失败', $exception->getMessage());
        }
        self::assertEquals($grants, DB::table('admin_grants')->get()->toArray());
        self::assertSame($state, app(LifecycleStore::class)->read('usage-addon'));
        $this->install(update: true);
        self::assertTrue(app(AuthorizationService::class)->allows($this->founder, 'export', 'usage.entries'));
    }

    public function testOptionsAndTaskInputsAreFounderOnlyAndValidateRolesAgainAtExecution(): void
    {
        Bus::fake();
        $this->login($this->founder);
        $this->getJson('/ptadmin/addons/usage-addon/usage-permissions')->assertOk()
            ->assertJsonPath('data.selection.founder', true)->assertJsonPath('data.roles.0.id', $this->role);
        $this->postJson('/ptadmin/addons/usage-addon/install', ['source' => $this->root.'/package',
            'usage_permissions' => ['founder' => false, 'role_ids' => [99999]],
        ])->assertStatus(422);
        $selection = ['founder' => false, 'role_ids' => [$this->role]];
        $request = ['source' => $this->root.'/package', 'usage_permissions' => $selection];
        $task = $this->postJson('/ptadmin/addons/usage-addon/install', $request, ['Idempotency-Key' => 'usage-install'])
            ->assertStatus(202)->assertJsonPath('data.payload.usage_permissions', $selection)->json('data');
        $request['usage_permissions']['founder'] = true;
        $this->postJson('/ptadmin/addons/usage-addon/install', $request, ['Idempotency-Key' => 'usage-install'])->assertStatus(422);
        DB::table('admin_roles')->where('id', $this->role)->update(['status' => 0]);
        try {
            $this->install($task['payload']['usage_permissions']);
            self::fail('Inactive role must be rejected before activation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('usage_permissions.role_ids', $exception->errors());
        }
        self::assertDirectoryDoesNotExist($this->root.'/addons/usage-addon');
        self::assertSame(0, DB::table('admin_grants')->count());
        $other = Admin::query()->forceCreate(['username' => 'other', 'password' => 'unused', 'status' => 1]);
        $this->login($other);
        $this->getJson('/ptadmin/addons/usage-addon/usage-permissions')->assertForbidden();
        $this->postJson('/ptadmin/addons/usage-addon/install', $request)->assertForbidden();
    }

    public function testCloudConfirmationKeepsLocalRolesOutOfPlatformRequests(): void
    {
        config(['ptadmin.addon.require_signature' => true]);
        SignedAddonPackage::sign($this->root.'/package');
        Bus::fake();
        $this->login($this->founder);
        app(CloudSessionManager::class)->store(['access_token' => 'test-token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]);
        $client = $this->createMock(ProductDeliveryClientInterface::class);
        $client->expects(self::once())->method('deliveryOptions')->willReturn([
            'product' => ['code' => 'usage-addon', 'product_type' => 'addon'], 'licenses' => [],
        ]);
        $client->expects(self::once())->method('prepareDelivery')->with('test-token', self::callback(
            static fn (array $payload): bool => !array_key_exists('usage_permissions', $payload)
        ))->willReturn(['code' => 'usage-addon', 'version' => '1.0.0', 'product_type' => 'addon']);
        $client->expects(self::once())->method('downloadPackage')->willReturnCallback(function (string $token, array $payload, string $destination): array {
            self::assertArrayNotHasKey('usage_permissions', $payload);
            $zip = new \ZipArchive();
            $zip->open($destination, \ZipArchive::CREATE);
            $zip->addFile($this->root.'/package/manifest.json', 'manifest.json');
            $zip->addFile($this->root.'/package/resources/menu.json', 'resources/menu.json');
            $zip->close();
            return ['artifact' => $destination, 'sha256' => hash_file('sha256', $destination), 'size' => filesize($destination)];
        });
        $cloud = $this->createMock(CloudClientInterface::class);
        $cloud->expects(self::once())->method('previewLicense')->willReturn([
            'code' => 'usage-addon', 'version' => '1.0.0', 'product_type' => 'addon', 'core_version_range' => '*',
        ]);
        $this->app->instance(CloudClientInterface::class, $cloud);
        $this->app->instance(ProductDeliveryClientInterface::class, $client);
        $this->postJson('/ptadmin/cloud/delivery/options', ['code' => 'usage-addon', 'product_type' => 'addon'])
            ->assertOk()->assertJsonPath('data.usage_permissions.selection.founder', true);
        $selection = ['founder' => false, 'role_ids' => [$this->role]];
        $task = $this->postJson('/ptadmin/cloud/delivery/package', [
            'license_id' => 1, 'release_id' => 2, 'package_kind' => 'install', 'operation' => 'install', 'usage_permissions' => $selection,
        ])->assertStatus(202)->assertJsonPath('data.payload.usage_permissions', $selection)->json('data');
        self::assertSame(0, DB::table('admin_grants')->count());
        $tasks = app(TaskManager::class);
        $tasks->start($task['task_id']);
        app(CloudPackageExecutor::class)->execute($task, $tasks);
        self::assertSame('cloud_market', app(LifecycleStore::class)->read('usage-addon')['cloud_delivery']['source']);
        self::assertSame(0, DB::table('admin_grants')->where('subject_type', 'admin')->count());
        self::assertSame(1, DB::table('admin_grants')->where('subject_type', 'role')->count());
        self::assertSame($selection, app(AddonUsagePermissions::class)->options('usage-addon')['selection']);
    }

    public function testLocalInstallRequestCannotSupplyCloudTrust(): void
    {
        Bus::fake();
        config(['ptadmin.addon.require_signature' => true]);
        SignedAddonPackage::sign($this->root.'/package');
        $this->login($this->founder);
        $claim = ['source' => 'cloud_market', 'code' => 'usage-addon', 'version' => '1.0.0'];
        $task = $this->postJson('/ptadmin/addons/usage-addon/install', [
            'source' => $this->root.'/package', 'cloud_delivery' => $claim, 'cloudTrust' => $claim,
        ])->assertStatus(202)->json('data');
        self::assertArrayNotHasKey('cloud_delivery', $task['payload']);
        self::assertArrayNotHasKey('cloudTrust', $task['payload']);
        $tasks = app(TaskManager::class);
        $tasks->start($task['task_id']);
        $task['payload']['cloud_delivery'] = $claim;
        try {
            app(AddonTaskExecutor::class)->execute($task, $tasks);
            self::fail('本地任务不得从任务载荷取得云市场信任。');
        } catch (AddonException $exception) {
            self::assertSame('trusted_policy_unconfigured', $exception->errorCode);
        }
        self::assertNull(app(LifecycleStore::class)->read('usage-addon'));
        self::assertSame(0, DB::table('admin_grants')->count());
    }

    private function login(Admin $admin): void
    {
        $this->withToken(app(SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token']);
    }

    private function install(?array $selection = null, bool $update = false): void
    {
        app(Installer::class)->install($this->root.'/package', 'console', $update, usagePermissions: $selection);
    }

    private function package(array $abilities = ['list', 'view', 'delete'], bool $reports = false): void
    {
        file_put_contents($this->root.'/package/manifest.json', json_encode(['code' => 'usage-addon', 'version' => '1.0.0']));
        $menus = [['resource_key' => 'usage.entries', 'title' => '插件内容', 'type' => 'page', 'path' => 'usage', 'is_menu' => true, 'entry_permission' => 'usage.entries.list', 'permissions' => [['resource_key' => 'usage.entries', 'operations' => array_map(static fn (string $key): array => ['key' => $key], $abilities)]]]];
        if ($reports) {
            $menus[] = ['resource_key' => 'usage.reports', 'title' => '报表', 'type' => 'page', 'path' => 'reports', 'is_menu' => true, 'entry_permission' => 'usage.reports.list', 'permissions' => [['resource_key' => 'usage.reports', 'operations' => [['key' => 'list']]]]];
        }
        file_put_contents($this->root.'/package/resources/menu.json', json_encode($menus));
    }
}
