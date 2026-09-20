<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Runtime\RuntimeRegistrySnapshot;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;

final class RuntimeRegistryValidatorTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function testMissingBindingIsReportedEvenWhenRuntimeHasNoResourceDescriptor(): void
    {
        $this->resource('cms.articles', ['publish' => ['enabled' => true]]);

        $diagnostics = (new RuntimeRegistryValidator())->validate($this->snapshot([]));

        self::assertSame([
            ['code' => 'action_binding_missing', 'resource_key' => 'cms.articles', 'action_key' => 'publish'],
        ], $diagnostics);
    }

    public function testNotDeclaredAndDisabledSchemaActionsAreHandled(): void
    {
        $this->resource('cms.articles', [
            'publish' => ['enabled' => true],
            'archive' => ['enabled' => false],
        ]);

        $diagnostics = (new RuntimeRegistryValidator())->validate($this->snapshot([
            'cms.articles' => ['actions' => ['publish' => [], 'archive' => [], 'unknown' => []]],
        ]));

        self::assertSame([
            ['code' => 'action_not_declared', 'resource_key' => 'cms.articles', 'action_key' => 'archive'],
            ['code' => 'action_not_declared', 'resource_key' => 'cms.articles', 'action_key' => 'unknown'],
        ], $diagnostics);
    }

    public function testResourceDiagnosticsIncludeMissingActiveSchemaAndDisabledResource(): void
    {
        $this->database->table('schema_resources')->insert([
            ['resource_key' => 'cms.missing', 'status' => 'draft'],
            ['resource_key' => 'cms.disabled', 'status' => 'disabled'],
        ]);

        $diagnostics = (new RuntimeRegistryValidator())->validate($this->snapshot([
            'cms.unknown' => ['actions' => []],
            'cms.missing' => ['actions' => []],
            'cms.disabled' => ['actions' => []],
        ]));

        self::assertSame([
            ['code' => 'resource_disabled', 'resource_key' => 'cms.disabled'],
            ['code' => 'active_schema_not_found', 'resource_key' => 'cms.missing'],
            ['code' => 'resource_not_found', 'resource_key' => 'cms.unknown'],
        ], $diagnostics);
    }

    public function testReservedAndEmptyActionsDoNotCreateDiagnostics(): void
    {
        $this->resource('cms.articles', [
            'list' => ['enabled' => true],
            'publish' => [],
        ]);

        $diagnostics = (new RuntimeRegistryValidator())->validate($this->snapshot([
            'cms.articles' => ['actions' => ['list' => [], 'publish' => []]],
        ]));

        self::assertSame([], $diagnostics);
    }

    public function testScopedValidationIgnoresUnrelatedRuntimeResources(): void
    {
        $this->resource('cms.articles', ['publish' => ['enabled' => true]]);

        $diagnostics = (new RuntimeRegistryValidator())->validate($this->snapshot([
            'cms.articles' => ['actions' => []],
            'admin.users' => ['actions' => []],
        ]), ['cms.articles']);

        self::assertSame([
            ['code' => 'action_binding_missing', 'resource_key' => 'cms.articles', 'action_key' => 'publish'],
        ], $diagnostics);
    }

    /** @param array<string, mixed> $actions */
    private function resource(string $resourceKey, array $actions): void
    {
        $id = (int) $this->database->table('schema_resources')->insertGetId([
            'resource_key' => $resourceKey,
            'status' => 'active',
        ]);
        $this->database->table('schema_resource_versions')->insert([
            'resource_id' => $id,
            'status' => 'active',
            'schema_json' => json_encode(['actions' => $actions], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, array<string, mixed>> $resources */
    private function snapshot(array $resources): RuntimeRegistrySnapshot
    {
        return new RuntimeRegistrySnapshot(['format' => 1, 'resources' => $resources]);
    }
}
