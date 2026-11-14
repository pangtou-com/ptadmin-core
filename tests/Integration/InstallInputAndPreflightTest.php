<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Core\Install\AdminFrontendPublisher;
use PTAdmin\Core\Install\EnvironmentFile;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\InstallDatabaseDrivers;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use Symfony\Component\Process\Process;

final class InstallInputAndPreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-install-preflight-'.bin2hex(random_bytes(6));
        foreach (['public', 'resources', 'bootstrap/cache'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        parent::setUp();
        $this->app->setBasePath($this->root);
        $this->app->make(Kernel::class)->pushMiddleware(ConvertEmptyStringsToNull::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->root.'/storage');
        $app->usePublicPath($this->root.'/public');
        $app->useEnvironmentPath($this->root);
        $app['config']->set([
            'app.key' => '',
            'database.default' => 'not_configured',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'view.compiled' => $this->root.'/storage/views',
            'ptadmin.addon.path' => $this->root.'/addons',
            'ptadmin.install.extensions' => [],
            'ptadmin.install.folders' => [],
            'ptadmin.install.files' => [],
        ]);
    }

    public static function databaseInputs(): iterable
    {
        foreach (['flat', 'nested', 'wizard'] as $endpoint) {
            yield $endpoint.' omitted' => [$endpoint, [], 'saved-password', 'pt_'];
            yield $endpoint.' empty strings' => [$endpoint, ['password' => '', 'prefix' => ''], '', ''];
            yield $endpoint.' null values' => [$endpoint, ['password' => null, 'prefix' => null], '', ''];
        }
    }

    #[DataProvider('databaseInputs')]
    public function testHttpInputsDistinguishEmptyValuesFromOmittedFields(
        string $endpoint,
        array $values,
        string $expectedPassword,
        string $expectedPrefix,
    ): void {
        config(['database.connections.pgsql.password' => 'saved-password']);
        $this->app->instance(InstallDatabaseDrivers::class, new InstallDatabaseDrivers(['pgsql']));
        $captured = null;
        // 在实际连接边界捕获配置，避免验证空密码时连接任何外部数据库。
        $this->app['db']->extend('pgsql', static function (array $config) use (&$captured): void {
            $captured = $config;
            throw new InvalidInstallStateException('Connection intercepted for installation input test.');
        });
        $input = $this->installationInput();
        if ($endpoint === 'nested') {
            $input['database'] = $values + [
                'driver' => 'pgsql', 'host' => 'localhost', 'port' => '5432',
                'database' => 'installation', 'username' => 'installer',
            ];
        } else {
            $input += [
                'db_connection' => 'pgsql', 'db_host' => 'localhost', 'db_port' => '5432',
                'db_database' => 'installation', 'db_username' => 'installer',
            ];
            foreach ($values as $key => $value) {
                $input['db_'.$key] = $value;
            }
        }
        if ($endpoint === 'wizard') {
            $this->post('/__ptadmin__/install/accept')->assertRedirect();
            $response = $this->post('/__ptadmin__/install/stream', $input)->assertOk();
            self::assertStringContainsString('Connection intercepted', $response->streamedContent());
        } else {
            $this->postJson('/__ptadmin__/install', $input)->assertStatus(409)
                ->assertJsonPath('message', 'Connection intercepted for installation input test.');
        }
        self::assertNotNull($captured);
        self::assertSame($expectedPassword, $captured['password']);
        self::assertSame($expectedPrefix, $captured['prefix']);
        self::assertSame([], $this->app['db']->getConnections());
        self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
    }

    public static function filesystemConflicts(): iterable
    {
        yield 'storage directory' => ['storage', 'public storage link'];
        yield 'frontend file' => ['target-file', 'not a directory'];
        yield 'frontend directory without manifest' => ['target-directory', 'manifest'];
        yield 'nested frontend parent is a file' => ['parent', 'parent directory'];
        yield 'missing bundled frontend' => ['bundle', 'directory is unavailable'];
    }

    #[DataProvider('filesystemConflicts')]
    public function testFilesystemConflictsFailBeforeDatabaseAndAllowRetry(string $conflict, string $message): void
    {
        $prefix = $conflict === 'parent' ? 'review/console' : 'review';
        $source = config('ptadmin.frontend.bundled_path');
        $asset = match ($conflict) {
            'storage' => $this->root.'/public/storage',
            'target-file', 'target-directory', 'parent' => $this->root.'/public/review',
            default => $this->root.'/missing-bundle',
        };
        if (in_array($conflict, ['storage', 'target-directory'], true)) {
            mkdir($asset);
            file_put_contents($asset.'/keep.txt', 'user asset');
        } elseif ($conflict === 'bundle') {
            config(['ptadmin.frontend.bundled_path' => $asset]);
        } else {
            file_put_contents($asset, 'user asset');
        }
        $input = $this->installationInput() + [
            'ptadmin_web_prefix' => $prefix, 'db_connection' => 'sqlite',
            'db_database' => 'install.sqlite', 'db_prefix' => '',
        ];
        try {
            $this->app->make(InstallService::class)->install($input);
            self::fail('Filesystem conflicts must be detected before creating a database.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame([], $this->app['db']->getConnections());
        self::assertFileDoesNotExist($this->root.'/storage/database/install.sqlite');
        self::assertFileDoesNotExist($this->root.'/.env');
        self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
        self::assertSame('failed', $this->app->make(InstallStateStore::class)->readState()['stage']);

        // 仅清除测试主动创建的冲突，修正后使用同一份安装输入重试。
        if (is_dir($asset)) {
            self::assertSame('user asset', file_get_contents($asset.'/keep.txt'));
            (new Filesystem())->deleteDirectory($asset);
        } elseif (is_file($asset)) {
            self::assertSame('user asset', file_get_contents($asset));
            unlink($asset);
        }
        config(['ptadmin.frontend.bundled_path' => $source]);
        $this->app->forgetInstance(AdminFrontendPublisher::class);
        $this->app->forgetInstance(InstallService::class);
        $result = $this->app->make(InstallService::class)->install($input);
        self::assertTrue($result['installed']);
        self::assertSame($prefix, $result['marker']['web_prefix']);
        self::assertSame(1, DB::table('admins')->count());
        self::assertTrue(is_link($this->root.'/public/storage'));
        self::assertFileExists($this->root.'/public/'.$prefix.'/index.html');
    }

    public function testHttpInstallationPersistsAnExplicitlyEmptyTablePrefix(): void
    {
        $this->postJson('/__ptadmin__/install', $this->installationInput() + [
            'db_connection' => 'sqlite', 'db_database' => 'install.sqlite', 'db_prefix' => '',
        ])->assertCreated()->assertJsonPath('data.result.database.prefix', '');
        self::assertSame('', $this->app->make(EnvironmentFile::class)->get('DB_PREFIX'));
        $tables = array_column(DB::select("select name from sqlite_master where type = 'table'"), 'name');
        self::assertContains('admins', $tables);
        self::assertNotContains('pt_admins', $tables);
    }

    public static function reloadableEnvironments(): iterable
    {
        foreach (['null', 'false', 'true', 'empty'] as $value) {
            yield 'reserved '.$value => ['', $value, $value];
        }
        yield 'duplicate prefix' => ["DB_PREFIX=first_\nDB_PREFIX=old_\n", 'new_', 'Review'];
        yield 'multiline name' => ["APP_NAME=\"Old\nName\"\n", 'pt_', 'Review'];
    }

    #[DataProvider('reloadableEnvironments')]
    public function testCompletedInstallationCanReloadItsEnvironment(string $contents, string $prefix, string $name): void
    {
        file_put_contents($this->root.'/.env', $contents);
        $input = array_replace($this->installationInput(), ['app_name' => $name]) + [
            'db_connection' => 'sqlite', 'db_database' => 'install.sqlite', 'db_prefix' => $prefix,
        ];
        $this->postJson('/__ptadmin__/install', $input)->assertCreated()->assertJsonPath('data.installed', true);

        $process = new Process([
            PHP_BINARY, dirname(__DIR__).'/Fixtures/Install/reload-environment.php',
            $this->root.'/.env', '--database',
        ]);
        $process->mustRun();
        $reloaded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($prefix, $reloaded['values']['DB_PREFIX']);
        self::assertSame($name, $reloaded['values']['APP_NAME']);
        self::assertSame($input['username'], $reloaded['administrator']);
        self::assertTrue($this->app->make(InstallStateStore::class)->isInstalled());
    }

    public function testUnwritableStagingParentFailsBeforeDatabaseAndCanBeRetried(): void
    {
        $public = $this->root.'/public';
        mkdir($public.'/nested', 0700);
        mkdir($this->root.'/storage/app/public', 0700, true);
        symlink($this->root.'/storage/app/public', $public.'/storage');
        $input = $this->installationInput() + [
            'ptadmin_web_prefix' => 'nested/console', 'db_connection' => 'sqlite',
            'db_database' => 'install.sqlite',
        ];
        chmod($public, 0555);
        clearstatcache();
        try {
            if (is_writable($public)) {
                self::markTestSkipped('当前进程可绕过目录权限，无法验证不可写目录。');
            }
            self::assertTrue(is_writable($public.'/nested'));
            try {
                $this->app->make(InstallService::class)->install($input);
                self::fail('The staging parent must be checked before database creation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('not writable: '.$public, $exception->getMessage());
            }
            self::assertSame([], $this->app['db']->getConnections());
            self::assertFileDoesNotExist($this->root.'/storage/database/install.sqlite');
            self::assertFileDoesNotExist($this->root.'/.env');
            self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
            self::assertSame('failed', $this->app->make(InstallStateStore::class)->readState()['stage']);
            self::assertSame($this->root.'/storage/app/public', readlink($public.'/storage'));
        } finally {
            chmod($public, 0700);
            clearstatcache();
        }

        $result = $this->app->make(InstallService::class)->install($input);
        self::assertTrue($result['installed']);
        self::assertSame(1, DB::table('admins')->count());
        self::assertFileExists($public.'/nested/console/index.html');
    }

    public static function invalidEnvironmentInputs(): iterable
    {
        foreach (['api', 'wizard', 'service'] as $endpoint) {
            yield $endpoint.' name newline' => [$endpoint, 'app_name', "Example\nSite"];
            yield $endpoint.' name carriage return' => [$endpoint, 'app_name', "Example\rSite"];
            yield $endpoint.' name null byte' => [$endpoint, 'app_name', "Example\0Site"];
            yield $endpoint.' URL newline' => [$endpoint, 'app_url', "https://example.test/\nsite"];
        }
    }

    #[DataProvider('invalidEnvironmentInputs')]
    public function testInvalidEnvironmentValuesFailBeforeAnyInstallationWrites(string $endpoint, string $field, string $value): void
    {
        $input = array_replace($this->installationInput(), [$field => $value]) + [
            'db_connection' => 'sqlite', 'db_database' => 'install.sqlite',
        ];
        if ($endpoint === 'api') {
            $response = $this->postJson('/__ptadmin__/install', $input);
            self::assertContains($response->getStatusCode(), [409, 422]);
        } elseif ($endpoint === 'wizard') {
            $this->post('/__ptadmin__/install/accept')->assertRedirect();
            $response = $this->post('/__ptadmin__/install/stream', $input)->assertOk();
            self::assertStringContainsString('"type":"error"', $response->streamedContent());
        } else {
            try {
                $this->app->make(InstallService::class)->install($input);
                self::fail('Invalid environment values must be rejected.');
            } catch (InvalidInstallStateException $exception) {
                self::assertStringContainsString('invalid', $exception->getMessage());
            }
        }

        self::assertSame([], $this->app['db']->getConnections());
        self::assertDirectoryDoesNotExist($this->root.'/storage/database');
        self::assertFileDoesNotExist($this->root.'/.env');
        self::assertFileDoesNotExist($this->root.'/storage/ptadmin.json');
        self::assertFalse(is_link($this->root.'/public/storage'));
        self::assertNull($this->app->make(InstallStateStore::class)->readState());
        self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
    }

    private function installationInput(): array
    {
        return [
            'username' => 'review-founder', 'password' => 'review-password',
            'password_confirmation' => 'review-password', 'nickname' => 'Review',
            'app_name' => 'Review', 'app_url' => 'https://review.example.test',
        ];
    }
}
