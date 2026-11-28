<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class AdminAuthCommandTest extends TestCase
{
    private string $storage;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-auth-command-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->storage);
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'session.driver' => 'array',
            'ptadmin.addon.path' => $this->storage.'/addons',
        ]);
        (new InstallStateStore($this->storage.'/app/ptadmin/install'))->markInstalled('0.13.1', 'admin_test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testResetCreatesOneTimePasswordAndRevokesAllSessions(): void
    {
        $admin = $this->createAdmin('recover-root');
        $sessions = app(SessionManager::class);
        $first = $sessions->issue(Admin::class, $admin->id, 'admin')->toArray();
        $second = $sessions->issue(Admin::class, $admin->id, 'admin')->toArray();

        self::assertSame(0, Artisan::call('ptadmin:auth', ['action' => 'reset', '--username' => $admin->username]));
        $output = Artisan::output();
        preg_match('/密码：([A-Za-z0-9_-]+)/', $output, $matches);
        self::assertNotEmpty($matches[1] ?? null);
        self::assertTrue(Hash::check($matches[1], $admin->fresh()->password));
        foreach ([$first, $second] as $session) {
            $this->expectSessionRevoked($session['access_token']);
        }
    }

    public function testCreateMakesEnabledOrdinaryAdminWithoutPermissions(): void
    {
        $this->artisan('ptadmin:auth', ['action' => 'create', '-u' => 'operator'])
            ->expectsOutputToContain('未分配角色或直接权限')
            ->assertSuccessful();

        $admin = Admin::query()->where('username', 'operator')->firstOrFail();
        self::assertTrue((bool) $admin->status);
        self::assertFalse((bool) $admin->is_founder);
        self::assertSame(0, DB::table('admin_user_roles')->where('admin_id', $admin->id)->count());
        self::assertSame(0, DB::table('admin_grants')->where('subject_type', 'admin')->where('subject_id', (string) $admin->id)->count());
        self::assertNotSame('operator', $admin->password);
    }

    public function testDeleteRequiresConfirmationAndProtectsFounder(): void
    {
        $founder = $this->createAdmin('founder', true);
        $this->artisan('ptadmin:auth', ['action' => 'delete', '--username' => 'founder', '--yes' => true])
            ->assertFailed();
        self::assertNull($founder->fresh()->deleted_at);

        $admin = $this->createAdmin('operator');
        $session = app(SessionManager::class)->issue(Admin::class, $admin->id, 'admin')->toArray();
        $this->artisan('ptadmin:auth', ['action' => 'delete', '--username' => 'operator'])
            ->expectsConfirmation('确认软删除管理员 operator 并撤销其会话？', false)
            ->assertFailed();
        self::assertNull($admin->fresh()->deleted_at);
        $this->artisan('ptadmin:auth', ['action' => 'delete', '--username' => 'operator', '--no-interaction' => true])
            ->assertFailed();
        self::assertNull($admin->fresh()->deleted_at);
        $roleId = DB::table('admin_roles')->insertGetId([
            'code' => 'operator-role', 'name' => '操作员', 'status' => 1, 'is_system' => 0,
            'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resourceId = DB::table('admin_resources')->insertGetId([
            'resource_key' => 'test.resource', 'title' => '测试资源', 'type' => 'page', 'parent_key' => '',
            'path' => '', 'meta_json' => '{}', 'is_menu' => 0, 'status' => 1, 'sort' => 0,
            'module' => 'test', 'level' => 0, 'route' => null, 'icon' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admin_user_roles')->insert([
            'admin_id' => $admin->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admin_grants')->insert([
            'subject_type' => 'admin', 'subject_id' => (string) $admin->id, 'resource_id' => $resourceId,
            'effect' => 'allow', 'abilities_json' => '["view"]', 'priority' => 0,
            'scope_type' => 'all', 'scope_value_json' => null, 'conditions_json' => null,
            'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('ptadmin:auth', ['action' => 'delete', '--username' => 'operator', '--yes' => true])
            ->assertSuccessful();
        self::assertNotNull(Admin::withTrashed()->findOrFail($admin->id)->deleted_at);
        self::assertSame(0, DB::table('admin_user_roles')->where('admin_id', $admin->id)->count());
        self::assertSame(0, DB::table('admin_grants')->where('subject_type', 'admin')->where('subject_id', (string) $admin->id)->count());
        $this->expectSessionRevoked($session['access_token']);
    }

    public function testCreateRejectsReusingSoftDeletedUsername(): void
    {
        $this->createAdmin('removed-user')->delete();
        $this->artisan('ptadmin:auth', ['action' => 'create', '--username' => 'removed-user'])
            ->assertFailed();
        self::assertSame(0, Admin::query()->where('username', 'removed-user')->count());
    }

    private function createAdmin(string $username, bool $founder = false): Admin
    {
        return Admin::query()->forceCreate([
            'username' => $username,
            'nickname' => $username,
            'password' => Hash::make('old-password'),
            'status' => true,
            'is_founder' => $founder,
        ]);
    }

    private function expectSessionRevoked(string $token): void
    {
        try {
            app(SessionManager::class)->resolve($token);
            self::fail('Expected the administrator session to be revoked.');
        } catch (\PTAdmin\Core\Auth\Exceptions\InvalidSessionException) {
            self::assertTrue(true);
        }
    }
}
