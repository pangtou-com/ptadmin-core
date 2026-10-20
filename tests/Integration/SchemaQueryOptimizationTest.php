<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Orchestra\Testbench\TestCase;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Runtime\RuntimeEngine;
use PTAdmin\Schema\Runtime\TreeService;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class SchemaQueryOptimizationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMetadataTables();
        $this->createResourceTable('schema_tree_nodes', ['parent_id', 'sort', 'title']);
        $this->createResourceTable('schema_categories', ['label']);
        $this->createResourceTable('schema_orders', ['category_id', 'category_name']);
        $this->seedResource('test.tree', 'schema_tree_nodes', [
            'name' => 'tree',
            'tree' => ['enabled' => true, 'key_field' => 'id', 'parent_field' => 'parent_id', 'order_field' => 'sort'],
            'fields' => [
                ['name' => 'parent_id', 'type' => 'integer', 'persisted' => true],
                ['name' => 'sort', 'type' => 'integer', 'persisted' => true],
                ['name' => 'title', 'type' => 'string', 'persisted' => true],
            ],
        ]);
        $this->seedResource('test.categories', 'schema_categories', [
            'name' => 'categories',
            'fields' => [
                ['name' => 'label', 'type' => 'string', 'persisted' => true],
            ],
        ]);
        $this->seedResource('test.orders', 'schema_orders', [
            'name' => 'orders',
            'fields' => [
                ['name' => 'category_id', 'type' => 'integer', 'persisted' => true, 'reference' => [
                    'resource' => 'test.categories', 'value_field' => 'id', 'label_field' => 'label',
                ]],
                ['name' => 'category_name', 'type' => 'string', 'persisted' => true, 'reference' => [
                    'resource' => 'test.categories', 'value_field' => 'id', 'label_field' => 'label',
                ], 'extensions' => ['mirror' => ['from_field' => 'category_id', 'source_field' => 'label']]],
            ],
        ]);
    }

    public function testTreeReadQueryCountIsIndependentOfNodeCount(): void
    {
        $this->insertTreeNodes(8);
        $small = $this->countQueries(fn (): array => (new TreeService())->getTree(['resource' => 'test.tree']));

        $this->insertTreeNodes(80);
        $large = $this->countQueries(fn (): array => (new TreeService())->getTree(['resource' => 'test.tree']));

        self::assertLessThanOrEqual(10, $small);
        self::assertLessThanOrEqual($small + 1, $large);
    }

    public function testResourceListLoadsVersionsInOneBatch(): void
    {
        $result = null;
        $queries = $this->countQueries(function () use (&$result): void {
            $result = app(ResourceServiceInterface::class)->listResources(['page' => 1, 'limit' => 3]);
        });

        self::assertLessThanOrEqual(4, $queries);
        self::assertCount(3, $result->results);
    }

    public function testRelationSelectedValuesAreLoadedInOneBatch(): void
    {
        foreach (range(1, 60) as $id) {
            DB::table('schema_categories')->insert(['id' => $id, 'label' => '分类 '.$id]);
        }
        $runtime = $this->runtime();
        $values = range(1, 60);
        $result = null;
        $queries = $this->countQueries(function () use ($runtime, $values, &$result): void {
            $result = $runtime->relationCandidates([
                'resource' => 'test.orders',
                'field' => 'category_id',
                'values' => $values,
                'limit' => 1,
            ]);
        });

        self::assertLessThanOrEqual(10, $queries);
        self::assertCount(60, $result['selected']);
    }

    public function testMirrorOutputIsLoadedInBatchForListRows(): void
    {
        DB::table('schema_categories')->insert([
            ['id' => 1, 'label' => '分类一'],
            ['id' => 2, 'label' => '分类二'],
        ]);
        foreach (range(1, 60) as $id) {
            DB::table('schema_orders')->insert(['id' => $id, 'category_id' => $id % 2 + 1, 'category_name' => '']);
        }

        $runtime = $this->runtime();
        $result = null;
        $queries = $this->countQueries(function () use ($runtime, &$result): void {
            $result = $runtime->list(['resource' => 'test.orders', 'page' => 1, 'limit' => 60]);
        });

        self::assertLessThanOrEqual(10, $queries);
        self::assertSame('分类二', $result['results'][0]['category_name']);
        self::assertSame('分类一', $result['results'][1]['category_name']);
    }

    private function runtime(): RuntimeEngine
    {
        $authorization = new class implements SchemaAuthorizationInterface {
            public function allows(mixed $subject, string $resourceKey, string $action): bool
            {
                return true;
            }
        };
        $subjectContext = new class implements SchemaSubjectContextInterface {
            public function set(mixed $subject): void
            {
            }

            public function get(): mixed
            {
                return null;
            }
        };

        return new RuntimeEngine($authorization, null, $subjectContext);
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            ++$count;
        });
        $callback();

        return $count;
    }

    private function createMetadataTables(): void
    {
        DatabaseSchema::create('schema_resources', static function (Blueprint $table): void {
            $table->id();
            $table->string('namespace')->default('');
            $table->string('name')->default('');
            $table->string('resource_key')->unique();
            $table->string('table_name');
            $table->string('migration_owner')->default('schema');
            $table->string('title')->default('');
            $table->text('description')->nullable();
            $table->string('source_type')->default('');
            $table->string('owner_type')->default('');
            $table->string('owner_code')->default('');
            $table->string('design_flow_id')->default('');
            $table->string('status');
            $table->unsignedBigInteger('active_version_id')->default(0);
            $table->boolean('allow_import')->default(false);
            $table->boolean('allow_export')->default(false);
            $table->boolean('allow_duplicate')->default(false);
            $table->boolean('allow_print')->default(false);
            $table->boolean('allow_recycle')->default(false);
            $table->boolean('allow_draft')->default(false);
            $table->string('draft_field')->default('');
            $table->json('draft_value')->nullable();
            $table->string('track_mode')->default('');
            $table->string('created_by')->default('');
            $table->string('updated_by')->default('');
            $table->timestamps();
        });
        DatabaseSchema::create('schema_resource_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id');
            $table->unsignedInteger('version');
            $table->string('status');
            $table->json('schema_json');
            $table->string('checksum');
            $table->string('check_status')->default('none');
            $table->timestamps();
            $table->index(['resource_id', 'status']);
        });
    }

    /** @param list<string> $columns */
    private function createResourceTable(string $tableName, array $columns): void
    {
        DatabaseSchema::create($tableName, static function (Blueprint $table) use ($columns): void {
            $table->id();
            foreach ($columns as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    /** @param array<string, mixed> $schema */
    private function seedResource(string $resourceKey, string $tableName, array $schema): void
    {
        $resourceId = DB::table('schema_resources')->insertGetId([
            'namespace' => 'test',
            'name' => str_replace('.', '_', substr($resourceKey, 5)),
            'resource_key' => $resourceKey,
            'table_name' => $tableName,
            'title' => $resourceKey,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = DB::table('schema_resource_versions')->insertGetId([
            'resource_id' => $resourceId,
            'version' => 1,
            'status' => 'active',
            'schema_json' => json_encode($schema, JSON_THROW_ON_ERROR),
            'checksum' => hash('sha256', $resourceKey),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schema_resources')->where('id', $resourceId)->update(['active_version_id' => $versionId]);
    }

    private function insertTreeNodes(int $count): void
    {
        $start = (int) DB::table('schema_tree_nodes')->max('id') + 1;
        foreach (range($start, $start + $count - 1) as $id) {
            DB::table('schema_tree_nodes')->insert([
                'id' => $id,
                'parent_id' => $id === $start ? null : $start,
                'sort' => $id,
                'title' => '节点 '.$id,
            ]);
        }
    }
}
