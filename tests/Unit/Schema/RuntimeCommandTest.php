<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Console\Commands\RuntimeCacheCommand;
use PTAdmin\Schema\Console\Commands\RuntimeValidateCommand;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;
use Symfony\Component\Console\Tester\CommandTester;

final class RuntimeCommandTest extends TestCase
{
    private Capsule $database;
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/ptadmin-runtime-command-'.bin2hex(random_bytes(5)).'/ptadmin-runtime.php';
        $this->database = new Capsule();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $schema = $this->database->schema();
        $schema->create('schema_resources', static function ($table): void {
            $table->increments('id');
            $table->string('resource_key');
            $table->string('status');
        });
        $schema->create('schema_resource_versions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('resource_id');
            $table->string('status');
            $table->text('schema_json');
        });
    }

    protected function tearDown(): void
    {
        if (is_file($this->cachePath)) {
            unlink($this->cachePath);
        }
        if (is_dir(dirname($this->cachePath))) {
            rmdir(dirname($this->cachePath));
        }
        parent::tearDown();
    }

    public function testValidateCommandReportsStableDiagnosticsAndFails(): void
    {
        $this->resource(['publish' => ['enabled' => true]]);
        $tester = $this->tester(new RuntimeValidateCommand(), new RuntimeRegistry());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('action_binding_missing', $tester->getDisplay());
        self::assertStringContainsString('cms.articles', $tester->getDisplay());
        self::assertStringContainsString('publish', $tester->getDisplay());
    }

    public function testCacheCommandWritesValidatedSnapshotAndCanClearIt(): void
    {
        $this->resource(['publish' => ['enabled' => true]]);
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->action('publish', RuntimeCommandHandler::class, 'publish');
        $tester = $this->tester(new RuntimeCacheCommand(), $registry);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->cachePath);
        self::assertStringContainsString('Runtime 缓存已重建', $tester->getDisplay());

        $clear = $this->tester(new RuntimeCacheCommand(), new RuntimeRegistry());
        self::assertSame(Command::SUCCESS, $clear->execute(['--clear' => true]));
        self::assertFileDoesNotExist($this->cachePath);
        self::assertStringContainsString('Runtime 缓存已清理', $clear->getDisplay());
    }

    /** @param array<string, mixed> $actions */
    private function resource(array $actions): void
    {
        $id = (int) $this->database->table('schema_resources')->insertGetId([
            'resource_key' => 'cms.articles',
            'status' => 'active',
        ]);
        $this->database->table('schema_resource_versions')->insert([
            'resource_id' => $id,
            'status' => 'active',
            'schema_json' => json_encode(['actions' => $actions], JSON_THROW_ON_ERROR),
        ]);
    }

    private function tester(Command $command, RuntimeRegistry $registry): CommandTester
    {
        $container = new Application(dirname($this->cachePath));
        $container->instance(RuntimeRegistry::class, $registry);
        $container->instance(RuntimeCacheStore::class, new RuntimeCacheStore($this->cachePath));
        $container->instance(RuntimeRegistryValidator::class, new RuntimeRegistryValidator());
        $command->setLaravel($container);

        return new CommandTester($command);
    }
}

final class RuntimeCommandHandler
{
    /** @return array<string, mixed> */
    public function publish(): array
    {
        return [];
    }
}
