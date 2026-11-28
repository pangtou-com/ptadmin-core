<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\PublishTask;
use PTAdmin\Schema\Meta\Service\DraftRecoveryService;
use PTAdmin\Schema\Publish\Service\PublishService;
use PTAdmin\Schema\Publish\Service\RetiredFieldPurger;
use PTAdmin\Schema\Publish\Service\FieldLifecycle;
use PTAdmin\Schema\Meta\Models\ResourceVersion;
use PTAdmin\Schema\Runtime\RuntimeEngine;

final class SchemaFieldRecoveryTest extends TestCase
{
    private ResourceServiceInterface $resources;
    private PublishService $publish;
    private ?\PDO $mysqlServer = null;
    private ?string $testDatabase = null;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $socket = getenv('PTADMIN_TEST_MYSQL_SOCKET');
        if ($socket !== false && $socket !== '') {
            $this->testDatabase = 'ptadmin_field_test_'.bin2hex(random_bytes(6));
            $this->mysqlServer = new \PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->mysqlServer->exec('CREATE DATABASE `'.$this->testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $this->testDatabase,
                'username' => 'root', 'password' => '', 'prefix' => 'pt_',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->mysqlServer !== null && $this->testDatabase !== null) {
            $this->mysqlServer->exec('DROP DATABASE `'.$this->testDatabase.'`');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_08_15_000001_create_schema_metadata_tables.php', '2026_11_17_180001_add_schema_field_recovery_state.php', '2026_11_28_180001_unique_schema_resource_versions.php'] as $file) {
            (require __DIR__.'/../../database/migrations/'.$file)->up();
        }
        $this->resources = app(ResourceServiceInterface::class);
        $this->publish = app(PublishService::class);
        $this->resources->createResource(['namespace' => 'test', 'name' => 'recovery', 'title' => '恢复测试', 'source_type' => 'studio']);
        $this->save($this->definition());
        $this->activate();
        DB::table('test_recovery')->insert(['title' => '原记录', 'notes' => '需要保留的数据']);
    }

    public function testConcurrentDraftEditorsReceiveSameVersion(): void
    {
        if ($this->testDatabase === null) {
            self::markTestSkipped('真实并发需要隔离 MySQL 测试库');
        }
        $directory = sys_get_temp_dir().'/schema-concurrent-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $workers = [];
        try {
            \PHPUnit\TextUI\Configuration\Registry::saveTo($directory.'/phpunit.config');
            for ($index = 0; $index < 2; $index++) {
                $command = [PHP_BINARY, __DIR__.'/../Fixtures/schema-draft-worker.php', getenv('PTADMIN_TEST_MYSQL_SOCKET'), $this->testDatabase, $directory.'/go', $directory.'/ready'.$index, $directory.'/phpunit.config'];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            while (!is_file($directory.'/ready0') || !is_file($directory.'/ready1')) {
                if (microtime(true) > $deadline) {
                    self::fail('并发测试进程未就绪');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $versions = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                $versions[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame($versions[0]['id'], $versions[1]['id']);
            self::assertSame($versions[0]['version'], $versions[1]['version']);
            self::assertSame(1, ResourceVersion::query()->where('status', 'draft')->count());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testBeginDraftReusesVersionAndDatabaseRejectsDuplicateVersion(): void
    {
        $first = $this->resources->beginDraftEdit('test.recovery')->toArray();
        $second = $this->resources->beginDraftEdit('test.recovery')->toArray();
        self::assertSame($first['version']['id'], $second['version']['id']);
        self::assertFalse($second['created']);
        $version = ResourceVersion::query()->findOrFail($first['version']['id']);
        try {
            $version->replicate()->save();
            self::fail('数据库必须拒绝重复版本号');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertContains((string) $error->getCode(), ['23000', '23505']);
        }
        self::assertSame(1, ResourceVersion::query()->where('status', 'draft')->count());
    }

    public function testUniqueVersionMigrationRejectsExistingDuplicatesWithoutDeletingThem(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_28_180001_unique_schema_resource_versions.php';
        $migration->down();
        $draft = $this->resources->beginDraftEdit('test.recovery')->toArray();
        $version = ResourceVersion::query()->findOrFail($draft['version']['id']);
        $duplicate = $version->replicate();
        $duplicate->save();
        try {
            $migration->up();
            self::fail('必须提示重复版本');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('存在重复版本', $error->getMessage());
        }
        self::assertSame(2, ResourceVersion::query()->where('status', 'draft')->count());
        $duplicate->delete();
        $migration->up();
        self::assertSame(1, ResourceVersion::query()->where('status', 'draft')->count());
    }

    public function testArchivedModelRejectsStaleEditorWritesCheckApprovalAndPublication(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        $this->publish->checkDraft(['resource' => 'test.recovery']);
        $this->publish->approveDraft(['resource' => 'test.recovery', 'approved_by' => 'tester']);
        $this->resources->archiveResource('test.recovery');
        $this->assertSchemaError('resource_archived', fn () => $this->resources->beginDraftEdit('test.recovery'));
        $this->assertSchemaError('resource_archived', fn () => $this->resources->saveDraftSchema(['resource' => 'test.recovery', 'schema' => $definition]));
        $this->assertSchemaError('resource_archived', fn () => $this->publish->checkDraft(['resource' => 'test.recovery']));
        $this->assertSchemaError('resource_archived', fn () => $this->publish->approveDraft(['resource' => 'test.recovery']));
        $this->assertSchemaError('resource_archived', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertSame('archived', Resource::query()->where('resource_key', 'test.recovery')->value('status'));
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    public function testRunningPublicationBlocksStatusChangesAndDraftOperations(): void
    {
        $draft = $this->resources->beginDraftEdit('test.recovery')->toArray();
        $resource = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        $task = PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => $draft['version']['id'], 'status' => 'running']);
        $this->assertSchemaError('publish_in_progress', fn () => $this->resources->archiveResource('test.recovery'));
        $this->assertSchemaError('publish_in_progress', fn () => $this->resources->disableResource('test.recovery'));
        $this->assertSchemaError('publish_in_progress', fn () => $this->resources->enableResource('test.recovery'));
        $this->assertSchemaError('publish_in_progress', fn () => $this->resources->beginDraftEdit('test.recovery'));
        $this->assertSchemaError('publish_in_progress', fn () => $this->publish->checkDraft(['resource' => 'test.recovery']));
        $this->assertSchemaError('publish_in_progress', fn () => $this->publish->approveDraft(['resource' => 'test.recovery']));
        self::assertSame('active', $resource->fresh()->status);
        $task->update(['status' => 'failed']);
        $this->resources->archiveResource('test.recovery');
        self::assertSame('archived', $resource->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cloneReferenceStates')]
    public function testCloneSourceBlocksDeletionInDraftAndPublishedModels(bool $publishConsumer): void
    {
        $consumer = $this->createCloneConsumer();
        if ($publishConsumer) {
            $this->publish->checkDraft(['resource' => 'test.consumer']);
            $this->publish->publishDraft(['resource' => 'test.consumer']);
        }
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertFalse($check['validation_result']['passed']);
        self::assertContains('field_still_referenced', array_column($check['validation_result']['errors'], 'code'));
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
        $this->resources->beginDraftEdit('test.consumer');
        unset($consumer['fields'][1]['extensions']['clone']);
        $this->resources->saveDraftSchema(['resource' => 'test.consumer', 'schema' => $consumer]);
        if ($publishConsumer) {
            self::assertFalse($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
            $this->publish->checkDraft(['resource' => 'test.consumer']);
            $this->publish->publishDraft(['resource' => 'test.consumer']);
        }
        self::assertTrue($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
    }

    public static function cloneReferenceStates(): array
    {
        return ['草稿引用' => [false], '已发布引用' => [true]];
    }

    public function testPublishRechecksCloneReferenceAddedAfterApproval(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        $this->publish->checkDraft(['resource' => 'test.recovery']);
        $this->publish->approveDraft(['resource' => 'test.recovery', 'approved_by' => 'tester']);
        $this->createCloneConsumer();
        $this->assertSchemaError('field_still_referenced', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preparationFailures')]
    public function testPreparationFailureEndsTaskAndAllowsRetry(int $failAt): void
    {
        $definition = $this->definition();
        $definition['title'] = '准备失败后重试';
        $this->save($definition);
        $this->publish->checkDraft(['resource' => 'test.recovery']);
        $armed = false;
        $remaining = $failAt;
        DB::listen(static function ($query) use (&$armed, &$remaining): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'update') && str_contains($sql, 'schema_publish_tasks') && in_array('running', $query->bindings, true)) {
                $armed = $remaining > 0;
            } elseif ($armed && str_starts_with($sql, 'select') && --$remaining === 0) {
                $armed = false;
                throw new \RuntimeException('模拟发布准备读取失败');
            }
        });
        $this->assertSchemaError('migration_failed', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        $task = PublishTask::query()->latest('id')->firstOrFail();
        self::assertSame('failed', $task->status);
        self::assertNotNull($task->finished_at);
        self::assertSame('模拟发布准备读取失败', $task->last_error_message);
        self::assertFalse(app(DraftRecoveryService::class)->info('test.recovery')['repair_required']);
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
        $info = app(DraftRecoveryService::class)->info('test.recovery');
        $draft = $this->resources->getDraftSchema('test.recovery')->toArray();
        app(DraftRecoveryService::class)->reset('test.recovery', $draft['version']['checksum'], $info['active_version']['id']);
        $this->save($definition);
        $this->activate();
        self::assertSame('succeeded', PublishTask::query()->latest('id')->firstOrFail()->status);
    }

    public static function preparationFailures(): array
    {
        return ['活动版本读取' => [1], '迁移计划读取' => [2], '数据库结构读取' => [3]];
    }

    private function createCloneConsumer(): array
    {
        $this->resources->createResource(['namespace' => 'test', 'name' => 'consumer', 'title' => '克隆引用', 'source_type' => 'studio']);
        $schema = ['name' => 'consumer', 'title' => '克隆引用', 'fields' => [
            ['name' => 'source_id', 'type' => 'integer', 'persisted' => true, 'reference' => ['resource' => 'test.recovery', 'value_field' => 'id', 'label_field' => 'title']],
            ['name' => 'copied_note', 'type' => 'string', 'persisted' => true, 'reference' => ['resource' => 'test.recovery', 'value_field' => 'id', 'label_field' => 'title'], 'extensions' => ['clone' => ['from_field' => 'source_id', 'source_field' => 'notes']]],
        ]];
        $this->resources->saveDraftSchema(['resource' => 'test.consumer', 'schema' => $schema]);
        return $schema;
    }

    public function testUnsupportedChangeIsRejectedDuringCheckAndDraftCanBeReset(): void
    {
        $definition = $this->definition();
        $definition['fields'][1]['type'] = 'integer';
        $this->save($definition);
        $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertFalse($check['validation_result']['passed']);
        self::assertSame('unsupported_field_change', $check['validation_result']['errors'][0]['code']);
        self::assertSame('fields.notes', $check['validation_result']['errors'][0]['path']);
        $info = app(DraftRecoveryService::class)->info('test.recovery');
        $draft = $this->resources->getDraftSchema('test.recovery')->toArray();
        $reset = app(DraftRecoveryService::class)->reset('test.recovery', $draft['version']['checksum'], $info['active_version']['id']);
        self::assertEquals($info['active_schema'], $reset['schema']);
        self::assertSame('none', $reset['version']['check_status']);
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
        self::assertTrue($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
    }

    public function testDeletedFieldIsOnlyDroppedAfterApprovalAndCannotRecoverHistoricalData(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
        $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertTrue($check['validation_result']['passed']);
        self::assertTrue($check['migration_plan']['destructive']);
        self::assertContains('drop_field:notes', array_column($check['migration_plan']['steps'], 'key'));
        $this->assertSchemaError('destructive_approval_required', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
        $this->activate();
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
        self::assertSame([], app(DraftRecoveryService::class)->info('test.recovery')['retired_fields']);
        self::assertSame('原记录', DB::table('test_recovery')->value('title'));
        $runtime = $this->runtime();
        self::assertArrayNotHasKey('notes', $runtime->detail(['resource' => 'test.recovery', 'id' => 1])['item']);
        $runtime->create(['resource' => 'test.recovery', 'data' => ['title' => '新增']]);
        $this->save($this->definition());
        $this->activate();
        self::assertNull(DB::table('test_recovery')->where('id', 1)->value('notes'));
    }

    public function testReservedRetiredNameCannotChangeStorageType(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->retireNotes();
        $definition['fields'][] = ['name' => 'notes', 'label' => '新含义', 'type' => 'integer', 'persisted' => true];
        $this->save($definition);
        $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertContains('retired_field_reserved', array_column($check['validation_result']['errors'], 'code'));
    }

    public function testDeletionRejectsIndexesAndPersistedToVirtualBypass(): void
    {
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->index('notes'));
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        self::assertFalse($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
        $definition = $this->definition();
        $definition['fields'][1]['persisted'] = false;
        $this->save($definition);
        self::assertContains('field_storage_protected', array_column($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['errors'], 'code'));
    }

    public function testResetRejectsStaleDraftAndDoesNotClearRepairMarker(): void
    {
        $info = app(DraftRecoveryService::class)->info('test.recovery');
        $definition = $this->definition();
        $definition['title'] = '修改';
        $this->save($definition);
        try {
            app(DraftRecoveryService::class)->reset('test.recovery', 'stale', $info['active_version']['id']);
            self::fail('旧草稿不能覆盖新修改。');
        } catch (SchemaError $error) {
            self::assertSame('checksum_mismatch', $error->getSchemaCode());
        }
        Resource::query()->where('resource_key', 'test.recovery')->update(['publish_repair_required' => true]);
        $draft = $this->resources->getDraftSchema('test.recovery')->toArray();
        self::assertTrue(app(DraftRecoveryService::class)->reset('test.recovery', $draft['version']['checksum'], $info['active_version']['id'])['repair_required']);
        self::assertFalse($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
    }

    public function testPublishRechecksDatabaseConstraintsAfterApprovalCheck(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        self::assertTrue($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
        $this->publish->approveDraft(['resource' => 'test.recovery', 'approved_by' => 'tester']);
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->unique('notes'));
        $this->assertSchemaError('field_deletion_blocked', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertTrue(DatabaseSchema::hasColumn('test_recovery', 'notes'));
    }

    public function testRequiredCanBecomeOptionalWithoutLosingDataDefaultsOrIndexes(): void
    {
        $definition = $this->definition();
        $definition['fields'][0]['required'] = true;
        // 首次发布一个带默认值的必填字段。
        $definition['fields'][] = ['name' => 'code', 'label' => '编码', 'type' => 'string', 'persisted' => true, 'required' => true, 'default' => '初始'];
        $this->save($definition);
        $this->activate();
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->index('code'));
        $indexes = DatabaseSchema::getIndexes('test_recovery');
        $columns = array_column(DatabaseSchema::getColumns('test_recovery'), null, 'name');
        self::assertFalse($columns['title']['nullable']);
        self::assertFalse($columns['code']['nullable']);
        $definition['fields'][0]['required'] = false;
        $definition['fields'][2]['required'] = false;
        $this->save($definition);
        $this->activate();
        $columns = array_column(DatabaseSchema::getColumns('test_recovery'), null, 'name');
        self::assertTrue($columns['title']['nullable']);
        self::assertTrue($columns['code']['nullable']);
        self::assertSame($indexes, DatabaseSchema::getIndexes('test_recovery'));
        self::assertSame('原记录', DB::table('test_recovery')->where('id', 1)->value('title'));
        self::assertSame('初始', DB::table('test_recovery')->where('id', 1)->value('code'));
        $this->runtime()->create(['resource' => 'test.recovery', 'data' => ['notes' => '可省略标题']]);
        self::assertNull(DB::table('test_recovery')->where('id', 2)->value('title'));
        self::assertSame('初始', DB::table('test_recovery')->where('id', 2)->value('code'));
    }

    public function testOptionalToRequiredChecksNullDataAgainAtPublish(): void
    {
        $definition = $this->definition();
        $definition['fields'][1]['required'] = true;
        $this->save($definition);
        self::assertTrue($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['passed']);
        DB::table('test_recovery')->insert(['title' => '未填写备注']);
        $this->assertSchemaError('field_backfill_required', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertContains('field_backfill_required', array_column($this->publish->checkDraft(['resource' => 'test.recovery'])->toArray()['validation_result']['errors'], 'code'));
        DB::table('test_recovery')->whereNull('notes')->update(['notes' => '已回填']);
        $this->activate();
        self::assertFalse(array_column(DatabaseSchema::getColumns('test_recovery'), null, 'name')['notes']['nullable']);
    }

    public function testPermanentDeleteRemovesOnlyRetiredColumnAndAllowsNextPublication(): void
    {
        $this->retireNotes();
        $draft = $this->resources->getDraftSchema('test.recovery')->toArray();
        $resource = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        $pending = PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => $draft['version']['id'], 'status' => 'pending']);
        $result = $this->purgeNotes();
        self::assertTrue($result['deleted']);
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
        self::assertSame('原记录', DB::table('test_recovery')->value('title'));
        self::assertSame([], app(DraftRecoveryService::class)->info('test.recovery')['retired_fields']);
        self::assertSame('cancelled', $pending->fresh()->status);
        self::assertSame('none', $this->resources->getDraftSchema('test.recovery')->toArray()['version']['check_status']);
        $task = PublishTask::query()->findOrFail($result['task_id']);
        self::assertSame('succeeded', $task->status);
        self::assertSame('tester', $task->triggered_by);
        self::assertSame('purge_retired_field', $task->migration_result_json['operation']);
        $this->activate();
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
    }

    public function testPermanentDeleteRejectsWrongConfirmationAndStaleState(): void
    {
        $this->retireNotes();
        $info = app(DraftRecoveryService::class)->info('test.recovery');
        $checksum = $this->resources->getDraftSchema('test.recovery')->toArray()['version']['checksum'];
        $purger = app(RetiredFieldPurger::class);
        $this->assertSchemaError('invalid_input', fn () => $purger->purge('test.recovery', 'notes', 'other', $checksum, $info['active_version']['id'], 'tester'));
        $this->assertSchemaError('checksum_mismatch', fn () => $purger->purge('test.recovery', 'notes', 'notes', 'stale', $info['active_version']['id'], 'tester'));
        $this->assertSchemaError('checksum_mismatch', fn () => $purger->purge('test.recovery', 'notes', 'notes', $checksum, $info['active_version']['id'] + 1, 'tester'));
        $this->assertSchemaError('field_not_retired', fn () => $purger->purge('test.recovery', 'title', 'title', $checksum, $info['active_version']['id'], 'tester'));
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    public function testPermanentDeleteRejectsRestoredDraftIndexesAndIncomingForeignKeys(): void
    {
        $this->retireNotes();
        $this->save($this->definition());
        $this->assertSchemaError('field_still_referenced', fn () => $this->purgeNotes());
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->unique('notes'));
        $this->assertSchemaError('field_still_referenced', fn () => $this->purgeNotes());
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->dropUnique(['notes']));
        // SQLite 允许声明外键后再建唯一约束，足以验证入向引用扫描，不执行该表写入。
        if (DB::getDriverName() === 'sqlite') {
            DatabaseSchema::create('external_refs', static function ($table): void {
                $table->string('note')->nullable()->comment('备注引用');
                $table->foreign('note')->references('notes')->on('test_recovery');
            });
            $this->assertSchemaError('field_still_referenced', fn () => $this->purgeNotes());
        }
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    public function testPermanentDeleteRejectsUnsafeResourceStates(): void
    {
        $this->retireNotes();
        $resource = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        foreach ([['source_type' => 'code'], ['migration_owner' => 'domain'], ['status' => 'archived'], ['publish_repair_required' => true]] as $change) {
            $before = $resource->only(array_keys($change));
            $resource->update($change);
            $this->assertSchemaError(isset($change['publish_repair_required']) ? 'structure_repair_required' : 'resource_readonly', fn () => $this->purgeNotes());
            $resource->update($before);
        }
        PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => $resource->active_version_id, 'status' => 'running']);
        $this->assertSchemaError('publish_in_progress', fn () => $this->purgeNotes());
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    public function testPermanentDeleteRecordsRepairStateIfMetadataCommitFailsAfterDdl(): void
    {
        $this->retireNotes();
        $failOnce = true;
        DB::listen(static function ($query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'retired_fields_json')) {
                $failOnce = false;
                throw new \RuntimeException('模拟元数据写入故障');
            }
        });
        $this->assertSchemaError('field_purge_failed', fn () => $this->purgeNotes());
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
        self::assertTrue(app(DraftRecoveryService::class)->info('test.recovery')['repair_required']);
        self::assertSame('failed', PublishTask::query()->latest('id')->firstOrFail()->status);
        $this->assertSchemaError('structure_repair_required', fn () => $this->purgeNotes());
    }

    public function testIncomingDraftReferencesUseOneQueryForTenAndOneHundredModels(): void
    {
        $this->retireNotes();
        $target = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        $counts = [];
        foreach ([10, 100] as $size) {
            for ($index = ($size === 10 ? 1 : 11); $index <= $size; $index++) {
                $resource = Resource::query()->create(['title' => '引用测试', 'name' => 'ref'.$index, 'resource_key' => 'test.ref'.$index, 'table_name' => 'test_ref'.$index]);
                ResourceVersion::query()->create(['resource_id' => $resource->id, 'version' => 1, 'status' => 'draft', 'checksum' => 'ref'.$index,
                    'schema_json' => ['fields' => [['name' => 'link'.$index, 'reference' => ['resource' => 'test.recovery', 'label_field' => 'title'], 'extensions' => ['clone' => ['from_field' => 'source_id', 'source_field' => 'notes']]]]]]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $references = (new FieldLifecycle())->incomingReferences($target, ['notes'], true);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            self::assertCount($size, $references['notes']);
            self::assertContains('test.ref'.$size.'.link'.$size, $references['notes']);
        }
        self::assertSame([1, 1], $counts);
        $this->assertSchemaError('field_still_referenced', fn () => $this->purgeNotes());
        self::assertSame('需要保留的数据', DB::table('test_recovery')->value('notes'));
    }

    public function testIncomingForeignKeysUseOneQueryForTenAndOneHundredTables(): void
    {
        DatabaseSchema::table('test_recovery', static fn ($table) => $table->unique('notes'));
        $target = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        $counts = [];
        foreach ([10, 100] as $size) {
            for ($index = ($size === 10 ? 1 : 11); $index <= $size; $index++) {
                DatabaseSchema::create('external_ref_'.$index, static function ($table): void {
                    $table->string('note')->nullable()->comment('外部备注引用');
                    $table->foreign('note')->references('notes')->on('test_recovery');
                });
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $references = (new FieldLifecycle())->incomingForeignKeys($target, ['notes', 'title']);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            self::assertCount($size, $references['notes']);
            self::assertContains(DB::getTablePrefix().'external_ref_'.$size, $references['notes']);
            self::assertArrayNotHasKey('title', $references);
        }
        self::assertSame([1, 1], $counts);
    }

    public function testRelaxedFieldsAcceptClearedFormValuesAndStillValidateNonEmptyInput(): void
    {
        $definition = $this->definition();
        $definition['fields'] = array_merge($definition['fields'], [
            ['name' => 'quantity', 'label' => '数量', 'type' => 'integer', 'persisted' => true, 'required' => true, 'default' => 5, 'validation' => [['type' => 'min', 'value' => 1]]],
            ['name' => 'price', 'label' => '价格', 'type' => 'number', 'persisted' => true, 'required' => true, 'default' => 5, 'validation' => [['type' => 'min', 'value' => 1]]],
            ['name' => 'enabled', 'label' => '启用', 'type' => 'boolean', 'persisted' => true, 'required' => true, 'default' => true],
            ['name' => 'day', 'label' => '日期', 'type' => 'date', 'persisted' => true],
            ['name' => 'tags', 'label' => '标签', 'type' => 'string', 'persisted' => true, 'multiple' => true],
            ['name' => 'category', 'label' => '分类', 'type' => 'string', 'persisted' => true, 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => [['value' => 'a', 'label' => '分类 A']]]],
        ]);
        $this->save($definition);
        $this->activate();
        foreach ($definition['fields'] as &$field) {
            $field['required'] = false;
        }
        unset($field);
        $this->save($definition);
        $this->activate();
        $runtime = $this->runtime();
        $cleared = array_fill_keys(['quantity', 'price', 'enabled', 'day', 'tags', 'category'], null);
        $runtime->create(['resource' => 'test.recovery', 'data' => $cleared + ['title' => '清空表单']]);
        $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => $cleared]);
        foreach ([1, 2] as $id) {
            $row = (array) DB::table('test_recovery')->where('id', $id)->first();
            foreach (array_keys($cleared) as $name) {
                self::assertNull($row[$name], $name.' 空值不能转换成其他业务值');
            }
        }
        $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => ['quantity' => '', 'price' => '', 'day' => '', 'tags' => [], 'category' => '']]);
        self::assertNull(DB::table('test_recovery')->where('id', 1)->value('quantity'));
        self::assertSame('[]', DB::table('test_recovery')->where('id', 1)->value('tags'));
        $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => ['enabled' => false]]);
        self::assertEquals(0, DB::table('test_recovery')->where('id', 1)->value('enabled'));
        $this->assertSchemaError('invalid_input', fn () => $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => ['quantity' => 0]]));
        $this->assertSchemaError('invalid_input', fn () => $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => ['category' => 'unknown']]));
    }

    public function testDeletingPublishedRequiredFieldAllowsNewRecordsAndUpdates(): void
    {
        $definition = $this->definition();
        $definition['fields'][1]['required'] = true;
        $this->save($definition);
        $this->activate();
        $runtime = $this->runtime();
        $this->assertSchemaError('invalid_input', fn () => $runtime->create(['resource' => 'test.recovery', 'data' => ['title' => '缺少必填字段']]));
        array_pop($definition['fields']);
        $this->save($definition);
        $this->activate();
        self::assertNotContains('notes', array_column($runtime->schemaSnapshot(['resource' => 'test.recovery'])['fields'], 'name'));
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
        $runtime->create(['resource' => 'test.recovery', 'data' => ['title' => '删除字段后新增']]);
        $runtime->update(['resource' => 'test.recovery', 'id' => 1, 'data' => ['title' => '删除字段后编辑', 'notes' => '旧表单值']]);
        self::assertSame('删除字段后编辑', DB::table('test_recovery')->where('id', 1)->value('title'));
        self::assertSame(2, DB::table('test_recovery')->count());
    }

    public function testDeletionRejectsIncomingForeignKeyAndDraftReference(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        $other = Resource::query()->create(['title' => '引用', 'name' => 'other', 'resource_key' => 'test.other', 'table_name' => 'test_other']);
        $version = ResourceVersion::query()->create(['resource_id' => $other->id, 'version' => 1, 'status' => 'draft', 'checksum' => 'reference',
            'schema_json' => ['fields' => [['name' => 'link', 'reference' => ['resource' => 'test.recovery', 'label_field' => 'title'], 'extensions' => ['clone' => ['from_field' => 'source_id', 'source_field' => 'notes']]]]]]);
        $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertContains('field_still_referenced', array_column($check['validation_result']['errors'], 'code'));
        $version->delete();
        if (DB::getDriverName() === 'sqlite') {
            DatabaseSchema::create('external_refs', static function ($table): void {
                $table->string('note')->nullable()->comment('外部备注引用');
                $table->foreign('note')->references('notes')->on('test_recovery');
            });
            $check = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
            self::assertContains('field_still_referenced', array_column($check['validation_result']['errors'], 'code'));
        }
        self::assertTrue(DatabaseSchema::hasColumn('test_recovery', 'notes'));
    }

    public function testDeletionMarksRepairRequiredWhenMetadataFailsAfterDdl(): void
    {
        $definition = $this->definition();
        array_pop($definition['fields']);
        $this->save($definition);
        $this->publish->checkDraft(['resource' => 'test.recovery']);
        $this->publish->approveDraft(['resource' => 'test.recovery', 'approved_by' => 'tester']);
        $failOnce = true;
        DB::listen(static function ($query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'schema_resources')) {
                $failOnce = false;
                throw new \RuntimeException('模拟删除后元数据失败');
            }
        });
        $this->assertSchemaError('migration_failed', fn () => $this->publish->publishDraft(['resource' => 'test.recovery']));
        self::assertFalse(DatabaseSchema::hasColumn('test_recovery', 'notes'));
        self::assertTrue(app(DraftRecoveryService::class)->info('test.recovery')['repair_required']);
        self::assertSame('failed', PublishTask::query()->latest('id')->firstOrFail()->status);
    }

    /** 构造既有停用字段记录；新发布的删除不再生成这种记录。 */
    private function retireNotes(): void
    {
        $resource = Resource::query()->where('resource_key', 'test.recovery')->firstOrFail();
        $active = ResourceVersion::query()->findOrFail($resource->active_version_id);
        $definition = $active->schema_json;
        $field = array_pop($definition['fields']);
        $resource->update(['retired_fields_json' => ['notes' => [
            'definition' => $field, 'retired_version' => $active->version,
            'column' => array_column(DatabaseSchema::getColumns('test_recovery'), null, 'name')['notes'],
        ]]]);
        $active->update(['schema_json' => $definition, 'checksum' => (new \PTAdmin\Schema\Definition\Schema($definition))->checksum()]);
        $this->resources->beginDraftEdit('test.recovery');
    }

    private function purgeNotes(): array
    {
        $info = app(DraftRecoveryService::class)->info('test.recovery');
        $checksum = $this->resources->getDraftSchema('test.recovery')->toArray()['version']['checksum'];
        return app(RetiredFieldPurger::class)->purge('test.recovery', 'notes', 'notes', $checksum, $info['active_version']['id'], 'tester');
    }

    private function assertSchemaError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('应拒绝操作：'.$code);
        } catch (SchemaError $error) {
            self::assertSame($code, $error->getSchemaCode(), $error->getMessage());
        }
    }

    private function definition(): array
    {
        return ['name' => 'recovery', 'title' => '恢复测试', 'fields' => [
            ['name' => 'title', 'label' => '标题', 'type' => 'string', 'persisted' => true],
            ['name' => 'notes', 'label' => '备注', 'type' => 'string', 'persisted' => true],
        ]];
    }

    private function save(array $schema): void
    {
        $this->resources->beginDraftEdit('test.recovery');
        $this->resources->saveDraftSchema(['resource' => 'test.recovery', 'schema' => $schema]);
    }

    private function activate(): void
    {
        $result = $this->publish->checkDraft(['resource' => 'test.recovery'])->toArray();
        self::assertTrue($result['validation_result']['passed'], json_encode($result['validation_result']));
        if ($result['migration_plan']['destructive']) {
            $this->publish->approveDraft(['resource' => 'test.recovery', 'approved_by' => 'tester']);
        }
        $this->publish->publishDraft(['resource' => 'test.recovery']);
    }

    private function runtime(): RuntimeEngine
    {
        return new RuntimeEngine(
            new class implements SchemaAuthorizationInterface {
                public function allows(mixed $subject, string $resourceKey, string $action): bool { return true; }
            },
            subjectContext: new class implements SchemaSubjectContextInterface {
                public function set(mixed $subject): void {}
                public function get(): mixed { return 1; }
            },
        );
    }
}
