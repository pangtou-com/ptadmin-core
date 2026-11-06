<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Resource\ResourceSynchronizer;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Schema\Meta\Models\Resource;
use RuntimeException;

final class AddonInstallationTransactionTest extends TestCase
{
    private string $root;
    private ?PDO $server = null;
    private ?string $database = null;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-ddl-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->root.'/storage');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pt_']);
        $app['config']->set('ptadmin.addon.path', $this->root.'/addons');
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== null) {
            DB::purge('mysql_addon_test');
            $this->server->exec('DROP DATABASE `'.$this->database.'`');
        }
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    public static function drivers(): array
    {
        return ['SQLite' => ['sqlite'], 'MySQL' => ['mysql']];
    }

    #[DataProvider('drivers')]
    public function testInstallationAndSetupAllowDdlBeforeTransactionalWrites(string $driver): void
    {
        $installer = $this->prepare($driver);
        $result = $installer->install($this->root.'/package', 'admintest');
        self::assertSame(2, $result['resources']['schemas']);
        self::assertSame('active', $this->state()->read('ddl-addon')['state']);
        self::assertSame('seeded', DB::table('addon_ddl_fixture')->value('title'));
        foreach (['ddl.items' => 'addon_ddl_items', 'ddl.preset' => 'addon_ddl_preset'] as $key => $table) {
            self::assertSame('active', Resource::query()->where('resource_key', $key)->value('status'));
            DB::table($table)->insert(['title' => '安装后写入', 'created_at' => now(), 'updated_at' => now()]);
            self::assertSame('安装后写入', DB::table($table)->value('title'));
            self::assertTrue(DB::connection()->getSchemaBuilder()->hasIndex($table, $table.'_title_index'));
        }
        self::assertFileExists($this->root.'/public/admintest/plugins/ddl-addon/entry.js');
        $installer->setup('ddl-addon', 'admintest');
        self::assertSame(1, DB::table('addon_ddl_fixture')->count());
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    #[DataProvider('drivers')]
    public function testFailurePreservesDdlAndBusinessDataAndCanBeRetried(string $driver): void
    {
        $installer = $this->prepare($driver);
        try {
            $installer->install($this->root.'/package', 'admintest', onProgress: static function (string $stage): void {
                if ($stage === 'publishing') {
                    throw new RuntimeException('模拟前端发布失败');
                }
            });
            self::fail('Installation must fail at frontend publication.');
        } catch (RuntimeException $exception) {
            self::assertSame('模拟前端发布失败', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root.'/addons/ddl-addon');
        self::assertDirectoryDoesNotExist($this->root.'/public/admintest/plugins/ddl-addon');
        self::assertNull($this->state()->read('ddl-addon'));
        self::assertSame('disabled', Resource::query()->where('resource_key', 'ddl.items')->value('status'));
        self::assertSame('seeded', DB::table('addon_ddl_fixture')->value('title'));
        DB::table('addon_ddl_preset')->insert(['title' => '保留业务数据', 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(0, DB::connection()->transactionLevel());
        $installer->install($this->root.'/package', 'admintest');
        self::assertSame('active', $this->state()->read('ddl-addon')['state']);
        self::assertSame('active', Resource::query()->where('resource_key', 'ddl.items')->value('status'));
        self::assertSame('保留业务数据', DB::table('addon_ddl_preset')->value('title'));
        self::assertSame(1, DB::table('addon_ddl_fixture')->count());
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    private function prepare(string $driver): Installer
    {
        if ($driver === 'mysql') {
            $socket = getenv('PTADMIN_TEST_MYSQL_SOCKET');
            if ($socket === false || $socket === '') {
                self::markTestSkipped('Set PTADMIN_TEST_MYSQL_SOCKET to an isolated MySQL test server.');
            }
            $this->database = 'ptadmin_addon_test_'.bin2hex(random_bytes(6));
            $this->server = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->server->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            config(['database.connections.mysql_addon_test' => [
                'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $this->database,
                'username' => 'root', 'password' => '', 'prefix' => 'pt_',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
            ]]);
            DB::setDefaultConnection('mysql_addon_test');
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach (['database/migrations', 'database/schemas', 'database/schema-presets', 'frontend/dist'] as $directory) {
            mkdir($this->root.'/package/'.$directory, 0700, true);
        }
        file_put_contents($this->root.'/package/manifest.json', json_encode(['code' => 'ddl-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/package/frontend/dist/entry.js', 'export default {}');
        file_put_contents($this->root.'/package/database/migrations/2026_11_06_000001_create_addon_ddl_fixture.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (!\Illuminate\Support\Facades\Schema::hasTable('addon_ddl_fixture')) {
            \Illuminate\Support\Facades\Schema::create('addon_ddl_fixture', function ($table): void {
                $table->id();
                $table->string('title')->comment('安装迁移回归测试数据');
            });
        }
    }
};
PHP);
        file_put_contents($this->root.'/package/database/migrations/2026_11_06_000002_seed_addon_ddl_fixture.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::transaction(function (): void {
            \Illuminate\Support\Facades\DB::table('addon_ddl_fixture')->insert(['title' => 'seeded']);
        });
    }
};
PHP);
        foreach (['schemas' => ['items', 'addon_ddl_items'], 'schema-presets' => ['preset', 'addon_ddl_preset']] as $directory => [$name, $table]) {
            file_put_contents($this->root.'/package/database/'.$directory.'/ddl.'.$name.'.schema.json', json_encode([
                'resource' => 'ddl.'.$name, 'table_name' => $table,
                'schema' => ['name' => $name, 'title' => '安装事务回归', 'constraints' => [
                    'indexes' => [['name' => $table.'_title_index', 'fields' => ['title']]],
                ], 'fields' => [
                    ['name' => 'title', 'type' => 'string', 'label' => '标题', 'description' => '安装后的业务内容'],
                ]],
            ], JSON_THROW_ON_ERROR));
        }
        return new Installer(
            new ManifestVerifier(), new PackageExtractor(), $this->state(),
            new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false,
            app(ResourceSynchronizer::class), DB::connection(), migrator: app('migrator'),
        );
    }

    private function state(): LifecycleStore
    {
        return new LifecycleStore($this->root.'/lifecycle');
    }
}
