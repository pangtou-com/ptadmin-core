<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class InitialAdministratorInstallationTest extends TestCase
{
    private string $root;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-initial-permissions-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/public', 0700, true);
        mkdir($this->root.'/resources', 0700, true);
        $app->useStoragePath($this->root.'/storage');
        $app->usePublicPath($this->root.'/public');
        $app->useEnvironmentPath($this->root);
        $app['config']->set('app.key', '');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->root.'/addons');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testInstallationSeedsExplicitHostAndCorePermissionsWithoutRegrantOnRepeat(): void
    {
        $this->app->setBasePath($this->root);
        file_put_contents($this->root.'/resources/menu.json', json_encode([[
            'resource_key' => 'host.orders', 'title' => '订单', 'type' => 'page', 'path' => 'orders',
            'is_menu' => true, 'status' => true, 'abilities' => ['list', 'update'],
        ]]));
        $input = ['username' => 'founder', 'password' => 'test-password', 'db_connection' => 'sqlite', 'db_database' => 'install.sqlite'];
        $result = app(InstallService::class)->install($input);
        self::assertTrue($result['installed']);
        $admin = Admin::query()->firstOrFail();
        $auth = app(AuthorizationService::class);
        self::assertTrue($admin->is_founder);
        self::assertSame([], $auth->roles($admin));
        self::assertTrue($auth->allows($admin, 'manage', 'cloud.market'));
        self::assertTrue($auth->allows($admin, 'list', 'host.orders'));
        self::assertTrue($auth->allows($admin, 'sync_permissions', 'admin.admin_users'));
        $count = DB::table('admin_grants')->count();
        self::assertGreaterThan(0, $count);
        $removed = DB::table('admin_resources')->where('resource_key', 'host.orders')->value('id');
        DB::table('admin_grants')->where('resource_id', $removed)->delete();
        $result = app(InstallService::class)->install($input);
        self::assertTrue($result['installed']);
        self::assertSame($count - 1, DB::table('admin_grants')->count());
        self::assertFalse($auth->allows($admin, 'list', 'host.orders'));
        self::assertSame(0, DB::table('admin_user_roles')->count());
        app(InstallService::class)->install($input);
        self::assertSame($count - 1, DB::table('admin_grants')->count());
    }
}
