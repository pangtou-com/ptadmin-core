<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Install\EnvironmentFile;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class InstallApplicationKeyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-install-key-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        parent::setUp();
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
        $app->useEnvironmentPath($this->root);
        $app['config']->set([
            'app.key' => '',
            'app.cipher' => 'AES-256-CBC',
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
    }

    public function testMissingEnvironmentCreatesAStableKeyBeforeDatabaseWork(): void
    {
        self::assertFileDoesNotExist($this->root.'/.env');
        $this->failBeforeDatabaseCreation();

        $key = (string) config('app.key');
        self::assertStringStartsWith('base64:', $key);
        self::assertSame(32, strlen(base64_decode(substr($key, 7), true)));
        self::assertSame($key, $this->app->make(EnvironmentFile::class)->get('APP_KEY'));
        self::assertSame(0600, fileperms($this->root.'/.env') & 0777);
        $encrypted = Crypt::encryptString('installation credential');
        self::assertSame('installation credential', Crypt::decryptString($encrypted));

        // 模拟下一次请求仍加载了空密钥配置；重试必须从环境文件恢复原密钥。
        config(['app.key' => '']);
        $this->failBeforeDatabaseCreation();
        self::assertSame($key, config('app.key'));
        self::assertSame('installation credential', Crypt::decryptString($encrypted));
        self::assertStringNotContainsString($key, (string) file_get_contents($this->app->make(InstallStateStore::class)->statePath()));
    }

    public function testEmptyEnvironmentKeyIsGeneratedWhileOtherSettingsArePreserved(): void
    {
        file_put_contents($this->root.'/.env', "# Keep host settings\nAPP_KEY=\nMAIL_HOST=mail.example.test\n");
        chmod($this->root.'/.env', 0640);
        $this->failBeforeDatabaseCreation();

        self::assertNotSame('', config('app.key'));
        self::assertSame(config('app.key'), $this->app->make(EnvironmentFile::class)->get('APP_KEY'));
        self::assertStringContainsString("# Keep host settings\n", (string) file_get_contents($this->root.'/.env'));
        self::assertSame('mail.example.test', $this->app->make(EnvironmentFile::class)->get('MAIL_HOST'));
        self::assertSame(0640, fileperms($this->root.'/.env') & 0777);
    }

    public function testConfiguredKeyIsPreservedAndSavedForTheNextRequest(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $key]);
        $encrypted = Crypt::encryptString('existing credential');
        $this->failBeforeDatabaseCreation();

        self::assertSame($key, config('app.key'));
        self::assertSame($key, $this->app->make(EnvironmentFile::class)->get('APP_KEY'));
        self::assertSame('existing credential', Crypt::decryptString($encrypted));
    }

    public function testExistingEnvironmentKeyIsUsedWhenRuntimeConfigurationIsEmpty(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->app->make(EnvironmentFile::class)->put('APP_KEY', $key);
        $this->failBeforeDatabaseCreation();

        self::assertSame($key, config('app.key'));
        self::assertSame($key, $this->app->make(EnvironmentFile::class)->get('APP_KEY'));
    }

    public function testInvalidInputDoesNotCreateAnEnvironmentOrKey(): void
    {
        try {
            $this->app->make(InstallService::class)->install(['username' => 'x', 'password' => '123']);
            self::fail('Invalid administrator input must be rejected.');
        } catch (InvalidInstallStateException $exception) {
            self::assertStringContainsString('username', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->root.'/.env');
        self::assertSame('', config('app.key'));
    }

    public function testGeneratedKeyMatchesTheConfiguredCipher(): void
    {
        config(['app.cipher' => 'AES-128-CBC']);
        $this->failBeforeDatabaseCreation();

        self::assertSame(16, strlen(base64_decode(substr((string) config('app.key'), 7), true)));
        self::assertSame('cipher-specific credential', Crypt::decryptString(Crypt::encryptString('cipher-specific credential')));
    }

    public function testEnvironmentWriteFailureStopsBeforeDatabaseCreation(): void
    {
        $this->app->instance(EnvironmentFile::class, new EnvironmentFile($this->root.'/missing/.env'));

        try {
            $this->app->make(InstallService::class)->install([
                'username' => 'key-founder',
                'password' => 'password123',
                'db_connection' => 'sqlite',
            ]);
            self::fail('The key must be persisted before database creation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Environment directory does not exist', $exception->getMessage());
        }

        self::assertSame('', config('app.key'));
        self::assertDirectoryDoesNotExist($this->root.'/storage/database');
        self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
    }

    private function failBeforeDatabaseCreation(): void
    {
        try {
            $this->app->make(InstallService::class)->install([
                'username' => 'key-founder',
                'password' => 'password123',
                'db_connection' => 'sqlite',
                'db_database' => '../invalid.sqlite',
            ]);
            self::fail('An invalid database path must interrupt installation.');
        } catch (InvalidInstallStateException $exception) {
            self::assertSame('SQLite 数据库只能填写文件名，不能包含目录路径。', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->root.'/storage/database/invalid.sqlite');
        self::assertFalse($this->app->make(InstallStateStore::class)->isInstalled());
    }
}
