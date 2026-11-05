<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PDO;
use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Schema\Audit\Service\AuditWriter;
use PTAdmin\Schema\Meta\Models\PublishTask;
use PTAdmin\Schema\Meta\Models\Resource;

final class MysqlTextMigrationTest extends TestCase
{
    private string $storage;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-text-migration-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->storage);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pt_']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testMysqlCreateStatementsDoNotGiveTextColumnsLiteralDefaults(): void
    {
        config(['database.connections.mysql_ddl' => ['driver' => 'mysql', 'database' => 'unused', 'prefix' => 'pt_']]);
        DB::setDefaultConnection('mysql_ddl');
        $queries = DB::connection()->pretend(static function (): void {
            foreach (['2026_08_15_000001_create_schema_metadata_tables.php', '2026_09_15_000005_create_ptadmin_config_tables.php'] as $filename) {
                $migration = require __DIR__.'/../../database/migrations/'.$filename;
                $migration->up();
            }
        });
        self::assertNotEmpty($queries);
        $sql = implode("\n", array_column($queries, 'query'));
        self::assertStringContainsString('create table `pt_schema_resources`', $sql);
        self::assertStringContainsString('create table `pt_ptadmin_config_definitions`', $sql);
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/`[^`]+` (?:tinytext|text|mediumtext|longtext|blob|json) (?:not null|null) default /i', $query['query']);
        }
        foreach (['资源说明', '配置用途说明', '最后错误信息', '客户端 User-Agent'] as $comment) {
            self::assertStringContainsString("comment '".$comment."'", $sql);
        }
    }

    public function testFreshSqliteMigrationsKeepEmptyTextAndLongDescriptions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTextWrites();
    }

    public function testFreshMysqlMigrationsKeepEmptyTextAndLongDescriptions(): void
    {
        $socket = getenv('PTADMIN_TEST_MYSQL_SOCKET');
        if ($socket === false || $socket === '') {
            self::markTestSkipped('Set PTADMIN_TEST_MYSQL_SOCKET to an isolated MySQL test server.');
        }
        $database = 'ptadmin_text_test_'.bin2hex(random_bytes(6));
        $server = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            config(['database.connections.mysql_test' => [
                'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database,
                'username' => 'root', 'password' => '', 'prefix' => 'pt_',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
            ]]);
            DB::setDefaultConnection('mysql_test');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertTextWrites();
        } finally {
            DB::purge('mysql_test');
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }

    private function assertTextWrites(): void
    {
        $resource = Resource::query()->create([
            'name' => 'text_test', 'resource_key' => 'test.text_test',
            'table_name' => 'text_test', 'title' => '兼容性验证资源',
        ]);
        self::assertSame('', $resource->fresh()->description);
        $task = PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => 1]);
        self::assertSame('', $task->fresh()->last_error_message);
        $definition = ConfigDefinition::query()->create([
            'group_code' => 'test', 'config_key' => 'text_test', 'owner_type' => 'core',
            'owner_code' => 'ptadmin', 'title' => '兼容性验证配置', 'schema_json' => [], 'checksum' => 'test',
        ]);
        self::assertSame('', $definition->fresh()->description);
        (new AuditWriter())->write('test.text_migration');
        self::assertSame('', DB::table('schema_audit_events')->value('user_agent'));

        $longText = str_repeat('中文说明与错误详情', 1000);
        $resource->update(['description' => $longText]);
        $definition->update(['description' => $longText]);
        $task->update(['last_error_message' => $longText]);
        (new AuditWriter())->write('test.long_user_agent', ['user_agent' => $longText]);
        self::assertSame($longText, $resource->fresh()->description);
        self::assertSame($longText, $definition->fresh()->description);
        self::assertSame($longText, $task->fresh()->last_error_message);
        self::assertSame($longText, DB::table('schema_audit_events')->where('action', 'test.long_user_agent')->value('user_agent'));
    }
}
