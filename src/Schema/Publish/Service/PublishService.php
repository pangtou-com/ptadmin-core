<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Publish\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use PTAdmin\Schema\Contract\ApproveDraftInput;
use PTAdmin\Schema\Contract\ApproveDraftResult;
use PTAdmin\Schema\Contract\CheckDraftInput;
use PTAdmin\Schema\Contract\CheckDraftResult;
use PTAdmin\Schema\Contract\GetDraftApprovalResult;
use PTAdmin\Schema\Contract\GetPublishTaskResult;
use PTAdmin\Schema\Contract\PublishDraftResult;
use PTAdmin\Schema\Contract\PublishDraftInput;
use PTAdmin\Schema\Contract\PublishTaskInfo;
use PTAdmin\Schema\Contract\RebuildFieldsProjectionResult;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Meta\Models\FieldProjection;
use PTAdmin\Schema\Meta\Models\PublishTask;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\ResourceVersion;
use PTAdmin\Schema\Meta\Service\ResourceLocator;
use PTAdmin\Schema\Support\Payload;

/**
 * 负责草稿校验、破坏性变更审批、数据库迁移和活动版本切换。
 * 发布失败必须保留任务阶段和错误信息，避免调用方误以为版本已生效。
 */
final class PublishService implements \PTAdmin\Schema\Contracts\PublishServiceInterface
{
    public function __construct(private ResourceLocator $locator)
    {
    }

    /** @param array<string, mixed>|object $input */
    public function checkDraft(array|object $input): CheckDraftResult
    {
        $input = Payload::dto($input, CheckDraftInput::class);
        $resource = $this->locator->resource((string) ($input['resource'] ?? ''));
        $draft = $this->locator->draft($resource);
        $expected = (string) ($input['expected_checksum'] ?? '');
        $this->assertChecksum($draft, $expected, 'checking');
        $schema = new Schema((array) $draft->schema_json);
        $issues = $schema->validate();
        $active = $resource->versions()->where('status', 'active')->first();
        $plan = $this->migrationPlan($resource, $active, $schema->toArray());
        $summary = $this->changeSummary($active ? (array) $active->schema_json : [], $schema->toArray());
        $validation = ['passed' => $issues === [], 'errors' => $issues, 'warnings' => []];
        $draft->check_status = $issues === [] ? 'passed' : 'failed';
        $draft->check_checksum = $draft->checksum;
        $draft->validation_result_json = $validation;
        $draft->migration_plan_json = $plan;
        $draft->change_summary_json = $summary;
        $draft->checked_at = now();
        $draft->save();
        return new CheckDraftResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($draft), 'validation_result' => $validation, 'migration_plan' => $plan, 'change_summary' => $summary]);
    }

    /** @param array<string, mixed>|object $input */
    public function approveDraft(array|object $input): ApproveDraftResult
    {
        $input = Payload::dto($input, ApproveDraftInput::class);
        $resource = $this->locator->resource((string) ($input['resource'] ?? ''));
        $draft = $this->locator->draft($resource);
        $expected = (string) ($input['expected_checksum'] ?? '');
        $this->assertChecksum($draft, $expected, 'checking');
        if ($draft->check_status !== 'passed' || $draft->check_checksum !== $draft->checksum) {
            throw $this->error('check_required', 'draft must pass check before approval', 'checking', ['resource_key' => $resource->resource_key]);
        }
        $plan = (array) ($draft->migration_plan_json ?? []);
        if (empty($plan['destructive'])) {
            throw $this->error('invalid_input', 'draft does not require destructive approval', 'checking', ['resource_key' => $resource->resource_key]);
        }
        $existing = PublishTask::query()->where('resource_id', $resource->id)->where('version_id', $draft->id)->where('status', 'pending')->where('check_checksum', $draft->check_checksum)->latest('id')->first();
        if ($existing) {
            return new ApproveDraftResult(['task' => $this->taskInfo($existing)]);
        }
        $task = PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => $draft->id, 'status' => 'pending', 'phase' => 'checking', 'triggered_by' => (string) ($input['approved_by'] ?? ''), 'check_checksum' => $draft->check_checksum]);
        return new ApproveDraftResult(['task' => $this->taskInfo($task)]);
    }

    public function getDraftApproval(string $resourceKey): GetDraftApprovalResult
    {
        $resource = $this->locator->resource($resourceKey);
        $draft = $this->locator->draft($resource);
        $task = PublishTask::query()->where('resource_id', $resource->id)->where('version_id', $draft->id)->where('status', 'pending')->latest('id')->first();
        $approved = $task && $task->check_checksum === $draft->checksum && $draft->check_checksum === $draft->checksum;
        return new GetDraftApprovalResult(['approved' => (bool) $approved, 'task' => $approved ? $this->taskInfo($task) : null]);
    }

    /** @param array<string, mixed>|object $input */
    public function publishDraft(array|object $input): PublishDraftResult
    {
        $input = Payload::dto($input, PublishDraftInput::class);
        $resource = $this->locator->resource((string) ($input['resource'] ?? ''));
        $draft = $this->locator->draft($resource);
        $expected = (string) ($input['expected_checksum'] ?? '');
        $this->assertChecksum($draft, $expected, 'draft');
        if ($draft->check_status !== 'passed' || $draft->check_checksum !== $draft->checksum) {
            throw $this->error('check_required', 'draft must pass check before publish', 'draft', ['resource_key' => $resource->resource_key]);
        }
        $plan = (array) ($draft->migration_plan_json ?? []);
        $approval = PublishTask::query()->where('resource_id', $resource->id)->where('version_id', $draft->id)->where('status', 'pending')->where('check_checksum', $draft->check_checksum)->latest('id')->first();
        if (!empty($plan['destructive']) && !$approval) {
            throw $this->error('destructive_approval_required', 'destructive migration requires approval', 'draft', ['resource_key' => $resource->resource_key]);
        }
        $task = DB::transaction(function () use ($resource, $draft, $approval): PublishTask {
            Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $running = PublishTask::query()->where('resource_id', $resource->id)->where('status', 'running')->latest('id')->first();
            if ($running) {
                throw $this->error('publish_in_progress', 'publish task is already running', 'draft', ['resource_key' => $resource->resource_key, 'task_id' => $running->id]);
            }
            if (PublishTask::query()->where('version_id', $draft->id)->where('status', 'succeeded')->exists()) {
                throw $this->error('version_already_published', 'draft version already published', 'draft', ['resource_key' => $resource->resource_key, 'version_id' => $draft->id]);
            }
            $task = $approval ?: PublishTask::query()->create(['resource_id' => $resource->id, 'version_id' => $draft->id, 'status' => 'pending', 'phase' => 'migration', 'check_checksum' => $draft->check_checksum]);
            $task->status = 'running';
            $task->phase = 'migration';
            $task->started_at = now();
            $task->save();
            return $task;
        });
        try {
            $migrationExecuted = $resource->migration_owner !== 'domain';
            if ($migrationExecuted) {
                $this->applyMigration($resource, $plan, (array) $draft->schema_json);
            }
            $task->migration_result_json = ['executed' => $migrationExecuted, 'steps' => count((array) ($plan['steps'] ?? [])), 'migration_owner' => $resource->migration_owner];
            $task->phase = 'activating';
            $task->save();
            DB::transaction(function () use ($resource, $draft, $task): void {
                $lockedResource = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
                $lockedDraft = ResourceVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
                $capabilities = (array) (($lockedDraft->schema_json ?? [])['capabilities'] ?? []);
                foreach (['allow_import', 'allow_export', 'allow_duplicate', 'allow_print', 'allow_recycle', 'allow_draft'] as $capability) {
                    $lockedResource->{$capability} = (bool) ($capabilities[$capability] ?? false);
                }
                $lockedResource->track_mode = (string) ($capabilities['track_mode'] ?? '');
                ResourceVersion::query()->where('resource_id', $lockedResource->id)->where('id', '!=', $lockedDraft->id)->where('status', 'active')->update(['status' => 'archived']);
                $lockedDraft->status = 'active';
                $lockedDraft->activated_at = now();
                $lockedDraft->check_status = 'passed';
                $lockedDraft->save();
                $lockedResource->active_version_id = $lockedDraft->id;
                $lockedResource->status = 'active';
                $lockedResource->save();
                $count = $this->rebuildProjection($lockedResource, $lockedDraft);
                $task->status = 'succeeded';
                $task->phase = 'projecting';
                $task->projection_result_json = ['count' => $count];
                $task->finished_at = now();
                $task->save();
            });
        } catch (SchemaError $exception) {
            $task->status = 'failed';
            $task->last_error_code = $exception->getSchemaCode();
            $task->last_error_message = $exception->getMessage();
            $task->finished_at = now();
            $task->save();
            throw $exception;
        } catch (\Throwable $exception) {
            $task->status = 'failed';
            $task->last_error_code = 'migration_failed';
            $task->last_error_message = $exception->getMessage();
            $task->finished_at = now();
            $task->save();
            throw $this->error('migration_failed', $exception->getMessage(), 'migration', ['resource_key' => $resource->resource_key]);
        }
        return new PublishDraftResult(['task' => $this->taskInfo($task)]);
    }

    public function getPublishTask(int $taskId): GetPublishTaskResult
    {
        $task = PublishTask::query()->find($taskId);
        if (!$task) {
            throw $this->error('publish_task_not_found', 'publish task not found', 'resource', ['task_id' => $taskId]);
        }
        return new GetPublishTaskResult(['task' => $this->taskInfo($task)]);
    }

    public function rebuildFieldsProjection(string $resourceKey): RebuildFieldsProjectionResult
    {
        $resource = $this->locator->resource($resourceKey);
        $version = $this->locator->active($resource, 'projection');
        $count = $this->rebuildProjection($resource, $version);
        return new RebuildFieldsProjectionResult(['resource' => $resourceKey, 'version' => $this->locator->versionInfo($version), 'count' => $count]);
    }

    /**
     * 执行迁移计划中的增删改列和约束；破坏性步骤只能在审批任务确认后进入此流程。
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $schema
     */
    private function applyMigration(Resource $resource, array $plan, array $schema): void
    {
        // Preflight the complete plan before changing the table. Column shape
        // changes need a dialect-aware migration runner; silently skipping one
        // would activate a schema that does not match the physical table.
        $unsupported = [];
        foreach ((array) ($plan['steps'] ?? []) as $step) {
            $key = (string) ($step['key'] ?? '');
            if (str_starts_with($key, 'alter_field:')) {
                $unsupported[] = $key;
            }
        }
        if ($unsupported !== []) {
            throw $this->error(
                'migration_failed',
                'migration plan contains unsupported SQL steps',
                'migration',
                ['unsupported_steps' => $unsupported],
            );
        }

        foreach ((array) ($plan['steps'] ?? []) as $step) {
            if (($step['risk'] ?? '') !== 'guarded' || !str_starts_with((string) ($step['key'] ?? ''), 'add_field:')) {
                continue;
            }
            if (DatabaseSchema::hasTable($resource->table_name) && DB::table($resource->table_name)->count() > 0) {
                throw $this->error(
                    'migration_failed',
                    'guarded field migration requires existing records to be backfilled first',
                    'migration',
                    [
                        'field' => substr((string) $step['key'], strlen('add_field:')),
                        'requires_backfill' => true,
                    ],
                );
            }
        }

        if (!DatabaseSchema::hasTable($resource->table_name)) {
            DatabaseSchema::create($resource->table_name, static function ($table) use ($schema): void {
                $table->bigIncrements('id');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                if (!empty($schema['capabilities']['allow_recycle'])) {
                    $table->softDeletes();
                }
                foreach ((array) ($schema['fields'] ?? []) as $field) {
                    if (!empty($field['persisted']) && !self::isManagedColumn((string) ($field['name'] ?? ''))) {
                        self::addColumn($table, $field);
                    }
                }
            });
            $constraints = (array) ($schema['constraints'] ?? []);
            $constraints['uniques'] = array_merge((array) ($constraints['uniques'] ?? []), $this->generatorUniques($schema));
            $this->applyConstraints($resource->table_name, $constraints);
            return;
        }
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if (empty($field['persisted']) || self::isManagedColumn((string) ($field['name'] ?? '')) || DatabaseSchema::hasColumn($resource->table_name, $field['name'])) {
                continue;
            }
            DatabaseSchema::table($resource->table_name, static function ($table) use ($field): void {
                self::addColumn($table, $field);
            });
        }
        foreach ((array) ($plan['steps'] ?? []) as $step) {
            $key = (string) ($step['key'] ?? '');
            if (str_starts_with($key, 'drop_field:')) {
                $column = substr($key, strlen('drop_field:'));
                if ($column !== '' && $column !== 'id' && DatabaseSchema::hasColumn($resource->table_name, $column)) {
                    try {
                        DatabaseSchema::table($resource->table_name, static fn ($table) => $table->dropColumn($column));
                    } catch (\Throwable $exception) {
                        throw $this->error('migration_failed', $exception->getMessage(), 'migration', ['field' => $column]);
                    }
                }
            } elseif (str_starts_with($key, 'drop_index:')) {
                $index = substr($key, strlen('drop_index:'));
                if ($index !== '' && $this->indexExists($resource->table_name, $index)) {
                    DatabaseSchema::table($resource->table_name, static fn ($table) => $table->dropIndex($index));
                }
            } elseif (str_starts_with($key, 'drop_unique:')) {
                $index = substr($key, strlen('drop_unique:'));
                if ($index !== '' && $this->indexExists($resource->table_name, $index)) {
                    DatabaseSchema::table($resource->table_name, static fn ($table) => $table->dropUnique($index));
                }
            } elseif ($key === 'add_system_column:deleted_at') {
                if (!DatabaseSchema::hasColumn($resource->table_name, 'deleted_at')) {
                    DatabaseSchema::table($resource->table_name, static fn ($table) => $table->softDeletes());
                }
            } elseif ($key === 'drop_system_column:deleted_at') {
                if (DatabaseSchema::hasColumn($resource->table_name, 'deleted_at')) {
                    DatabaseSchema::table($resource->table_name, static fn ($table) => $table->dropColumn('deleted_at'));
                }
            }
        }
        $constraints = (array) ($schema['constraints'] ?? []);
        $constraints['uniques'] = array_merge((array) ($constraints['uniques'] ?? []), $this->generatorUniques($schema));
        $this->applyConstraints($resource->table_name, $constraints);
    }

    private function applyConstraints(string $tableName, array $constraints): void
    {
        foreach ((array) ($constraints['indexes'] ?? []) as $constraint) {
            $fields = array_values(array_filter((array) ($constraint['fields'] ?? [])));
            if ($fields === []) {
                continue;
            }
            $name = (string) ($constraint['name'] ?? $this->defaultIndexName($tableName, $fields, 'index'));
            if ($this->matchingIndexExists($tableName, $fields, false, $name)) {
                continue;
            }
            if ($this->indexExists($tableName, $name)) {
                continue;
            }
            $name = $this->availableIndexName($tableName, $name);
            if ($this->indexExists($tableName, $name)) {
                continue;
            }
            DatabaseSchema::table($tableName, static function ($table) use ($fields, $name): void {
                $table->index($fields, $name);
            });
        }
        foreach ((array) ($constraints['uniques'] ?? []) as $constraint) {
            $fields = array_values(array_filter((array) ($constraint['fields'] ?? [])));
            if ($fields === []) {
                continue;
            }
            $name = (string) ($constraint['name'] ?? $this->defaultIndexName($tableName, $fields, 'unique'));
            if ($this->matchingIndexExists($tableName, $fields, true, $name)) {
                continue;
            }
            if ($this->indexExists($tableName, $name)) {
                continue;
            }
            $name = $this->availableIndexName($tableName, $name);
            if ($this->indexExists($tableName, $name)) {
                continue;
            }
            DatabaseSchema::table($tableName, static function ($table) use ($fields, $name): void {
                $table->unique($fields, $name);
            });
        }
    }

    /** @param list<string> $fields */
    private function defaultIndexName(string $tableName, array $fields, string $type): string
    {
        return strtolower(str_replace(['-', '.'], '_', $tableName.'_'.implode('_', $fields).'_'.$type));
    }

    /**
     * 发布幂等性只接受数据库元数据中真实存在的索引，权限、连接或数据冲突异常必须继续失败。
     */
    private function indexExists(string $tableName, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA index_list('.$tableName.')') as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }
            return false;
        }
        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->whereRaw('table_schema = database()')
                ->where('table_name', $tableName)
                ->where('index_name', $indexName)
                ->exists();
        }
        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', $tableName)->where('indexname', $indexName)->exists();
        }
        return false;
    }

    /**
     * Check index identity by table, ordered columns and uniqueness. This is
     * required on SQLite where a collision with another table can rename the
     * physical index away from the protocol-requested name.
     *
     * @param list<string> $fields
     */
    private function matchingIndexExists(string $tableName, array $fields, bool $unique, string $requestedName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $quotedTable = str_replace('"', '""', $tableName);
            foreach (DB::select('PRAGMA index_list("'.$quotedTable.'")') as $index) {
                if ((bool) ($index->unique ?? false) !== $unique) {
                    continue;
                }
                $indexName = (string) ($index->name ?? '');
                if ($indexName === '') {
                    continue;
                }
                $collisionName = $tableName.'_'.$requestedName;
                if (
                    $indexName !== $requestedName
                    && $indexName !== $collisionName
                    && preg_match('/^'.preg_quote($collisionName, '/').'_[0-9]+$/', $indexName) !== 1
                ) {
                    continue;
                }
                $quotedIndex = str_replace('"', '""', $indexName);
                $columns = array_map(
                    static fn ($column): string => (string) ($column->name ?? ''),
                    DB::select('PRAGMA index_info("'.$quotedIndex.'")'),
                );
                if ($columns === $fields) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * Index names are database-wide on SQLite. Keep the protocol name where possible,
     * but derive a stable table-qualified name when another table already owns it.
     *
     * @param string $tableName physical table name
     * @param string $requested requested protocol/index name
     */
    private function availableIndexName(string $tableName, string $requested): string
    {
        if (!$this->indexNameInUse($requested)) {
            return $requested;
        }

        $candidate = $tableName.'_'.$requested;
        $suffix = 2;
        while ($this->indexNameInUse($candidate)) {
            $candidate = $tableName.'_'.$requested.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function indexNameInUse(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return DB::selectOne(
                'select 1 from sqlite_master where type = ? and name = ? limit 1',
                ['index', $indexName],
            ) !== null;
        }
        // MySQL index names are scoped to a table; indexExists() above handles that case.
        if ($driver === 'mysql') {
            return false;
        }
        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')->where('indexname', $indexName)->exists();
        }

        return false;
    }

    private function generatorUniques(array $schema): array
    {
        $items = [];
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            $generator = (array) (($field['extensions'] ?? [])['generator'] ?? []);
            if (in_array(strtolower((string) ($generator['type'] ?? '')), ['sequence', 'slug'], true) && ($generator['unique'] ?? true) !== false && !empty($field['name'])) {
                $items[] = ['name' => $field['name'].'_unique', 'fields' => [$field['name']]];
            }
        }
        return $items;
    }

    private static function addColumn($table, array $field): void
    {
        $name = (string) $field['name'];
        $type = strtolower((string) ($field['type'] ?? 'string'));
        $multiple = !empty($field['multiple']);
        if ($multiple || $type === 'json') {
            $column = $table->json($name)->comment(self::fieldComment($field));
        } elseif ($type === 'integer') {
            $column = $table->integer($name)->comment(self::fieldComment($field));
        } elseif ($type === 'number') {
            $column = $table->double($name)->comment(self::fieldComment($field));
        } elseif ($type === 'boolean') {
            $column = $table->boolean($name)->comment(self::fieldComment($field));
        } elseif ($type === 'date' || $type === 'datetime') {
            $column = $table->bigInteger($name)->comment(self::fieldComment($field));
        } elseif ($type === 'text') {
            $column = $table->text($name)->comment(self::fieldComment($field));
        } else {
            $column = $table->string($name, 255)->comment(self::fieldComment($field));
        }
        if (empty($field['required'])) {
            $column->nullable();
        }
    }

    /**
     * 从 Schema 字段的业务元数据生成稳定的数据库字段说明。
     * 没有详细描述时使用字段标签，避免动态表产生无业务含义的空注释。
     *
     * @param array<string, mixed> $field Schema 字段定义
     */
    private static function fieldComment(array $field): string
    {
        $label = trim((string) ($field['label'] ?? ''));
        $description = trim((string) ($field['description'] ?? ''));
        if ($label !== '' && $description !== '') {
            return $label.'：'.$description;
        }
        if ($description !== '') {
            return $description;
        }
        if ($label !== '') {
            return $label;
        }

        return 'Schema 字段 '.(string) ($field['name'] ?? '未命名');
    }

    private static function isManagedColumn(string $name): bool
    {
        return in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true);
    }

    private function rebuildProjection(Resource $resource, ResourceVersion $version): int
    {
        $schema = (array) $version->schema_json;
        FieldProjection::query()->where('resource_id', $resource->id)->delete();
        $count = 0;
        foreach ((array) ($schema['fields'] ?? []) as $index => $field) {
            $type = strtolower((string) ($field['type'] ?? 'string'));
            $spec = in_array($type, ['string', 'number', 'integer', 'boolean', 'date', 'datetime'], true);
            $persisted = (bool) ($field['persisted'] ?? true);
            $extensions = self::projectFieldExtensions($field);
            FieldProjection::query()->create(['resource_id' => $resource->id, 'version_id' => $version->id, 'name' => $field['name'], 'sort' => $index + 1, 'type' => $type, 'label' => $field['label'] ?? '', 'column_name' => $persisted ? $field['name'] : '', 'persisted' => $persisted, 'multiple' => $field['multiple'] ?? false, 'required' => $field['required'] ?? false, 'readonly' => $field['readonly'] ?? false, 'disabled' => $field['disabled'] ?? false, 'formable' => array_key_exists('formable', $field) ? (bool) $field['formable'] : true, 'listable' => array_key_exists('listable', $field) ? (bool) $field['listable'] : ($persisted && $spec), 'searchable' => array_key_exists('searchable', $field) ? (bool) $field['searchable'] : ($persisted && $spec), 'sortable' => array_key_exists('sortable', $field) && (bool) $field['sortable'], 'extensions' => $extensions ?: null]);
            $count++;
        }
        return $count;
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private static function projectFieldExtensions(array $field): array
    {
        $definitions = (array) ($field['extensions'] ?? []);
        $projected = [];
        if (array_key_exists('generator', $definitions)) {
            $generator = (array) $definitions['generator'];
            $projected['generator'] = [
                'type' => (string) ($generator['type'] ?? ''),
                'source_field' => (string) ($generator['source_field'] ?? ''),
                'pattern' => (string) ($generator['pattern'] ?? ''),
                'unique' => array_key_exists('unique', $generator) ? (bool) $generator['unique'] : null,
                'on' => (string) ($generator['on'] ?? ''),
                'retry_limit' => (int) ($generator['retry_limit'] ?? 0),
                'allow_override' => (bool) ($generator['allow_override'] ?? false),
                'sequence' => $generator['sequence'] ?? null,
                'slug' => $generator['slug'] ?? null,
            ];
        }
        foreach (['mirror', 'clone'] as $name) {
            if (array_key_exists($name, $definitions)) {
                $extension = (array) $definitions[$name];
                $projected[$name] = [
                    'from_field' => (string) ($extension['from_field'] ?? ''),
                    'source_field' => (string) ($extension['source_field'] ?? ''),
                ];
            }
        }
        if (array_key_exists('secret', $definitions)) {
            $secret = (array) $definitions['secret'];
            $projected['secret'] = [
                'storage_mode' => (string) ($secret['storage_mode'] ?? ''),
                'readable' => (bool) ($secret['readable'] ?? false),
                'exportable' => (bool) ($secret['exportable'] ?? false),
            ];
        }
        if (array_key_exists('mask', $definitions)) {
            $mask = (array) $definitions['mask'];
            $projected['mask'] = [
                'type' => (string) ($mask['type'] ?? ''),
                'pattern' => (string) ($mask['pattern'] ?? ''),
            ];
        }
        if (array_key_exists('normalize', $field)) {
            $normalize = (array) $field['normalize'];
            $projected['normalize'] = [
                'trim' => (bool) ($normalize['trim'] ?? false),
                'empty_as_null' => (bool) ($normalize['empty_as_null'] ?? false),
                'case' => (string) ($normalize['case'] ?? ''),
                'accepted_formats' => (array) ($normalize['accepted_formats'] ?? []),
                'timezone' => (string) ($normalize['timezone'] ?? ''),
            ];
        }
        if (array_key_exists('display', $field)) {
            $display = (array) $field['display'];
            $projected['display'] = [
                'formatter' => (string) ($display['formatter'] ?? ''),
                'format' => (string) ($display['format'] ?? ''),
                'timezone' => (string) ($display['timezone'] ?? ''),
                'true_text' => (string) ($display['true_text'] ?? ''),
                'false_text' => (string) ($display['false_text'] ?? ''),
                'precision' => array_key_exists('precision', $display) ? (int) $display['precision'] : null,
                'thousand_separator' => (bool) ($display['thousand_separator'] ?? false),
                'prefix' => (string) ($display['prefix'] ?? ''),
                'suffix' => (string) ($display['suffix'] ?? ''),
            ];
        }

        return $projected;
    }

    /**
     * 对比活动版本生成可审阅的迁移计划，并标记删除列、类型变化等不可逆风险。
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function migrationPlan(Resource $resource, ?ResourceVersion $active, array $schema): array
    {
        $steps = [];
        $before = [];
        foreach ((array) ($active->schema_json['fields'] ?? []) as $field) {
            $before[$field['name']] = $field;
        }
        $beforeRecycle = $active !== null && !empty(((array) ($active->schema_json['capabilities'] ?? []))['allow_recycle']);
        $afterRecycle = !empty(((array) ($schema['capabilities'] ?? []))['allow_recycle']);
        if (DatabaseSchema::hasTable($resource->table_name)) {
            $hasDeletedAt = DatabaseSchema::hasColumn($resource->table_name, 'deleted_at');
            if ($afterRecycle && !$hasDeletedAt) {
                $steps[] = $this->migrationStep('add_system_column:deleted_at', 'column:'.$resource->table_name.'.deleted_at', 'safe');
            } elseif (!$afterRecycle && $beforeRecycle && $hasDeletedAt) {
                $steps[] = $this->migrationStep('drop_system_column:deleted_at', 'column:'.$resource->table_name.'.deleted_at', 'destructive');
            }
        }
        if ($active === null && $resource->table_name !== '' && array_filter((array) ($schema['fields'] ?? []), static fn (array $field): bool => !empty($field['persisted']))) {
            $steps[] = $this->migrationStep('create_table', 'table:'.$resource->table_name, 'safe');
        }
        $afterFields = [];
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            $afterFields[$name] = true;
            if (!isset($before[$name])) {
                if (!empty($field['persisted'])) {
                    $risk = $active !== null && !empty($field['required']) && !array_key_exists('default', $field) ? 'guarded' : 'safe';
                    $steps[] = $this->migrationStep('add_field:'.$name, 'column:'.$resource->table_name.'.'.$name, $risk, ['persisted']);
                }
                continue;
            }

            $wasPersisted = !empty($before[$name]['persisted']);
            $isPersisted = !empty($field['persisted']);
            if (!$wasPersisted && $isPersisted) {
                $risk = !empty($field['required']) && !array_key_exists('default', $field) ? 'guarded' : 'safe';
                $steps[] = $this->migrationStep('add_field:'.$name, 'column:'.$resource->table_name.'.'.$name, $risk, ['persisted']);
            } elseif ($wasPersisted && !$isPersisted) {
                $steps[] = $this->migrationStep('drop_field:'.$name, 'column:'.$resource->table_name.'.'.$name, 'destructive', ['persisted']);
            } elseif ($wasPersisted && $isPersisted) {
                $changes = $this->fieldStorageChanges((array) $before[$name], (array) $field);
                if ($changes !== []) {
                    $steps[] = $this->migrationStep('alter_field:'.$name, 'column:'.$resource->table_name.'.'.$name, $this->fieldStorageRisk((array) $before[$name], (array) $field, $changes), $changes);
                }
            }
        }
        foreach ($before as $name => $field) {
            if (!isset($afterFields[$name]) && !empty($field['persisted'])) {
                $steps[] = $this->migrationStep(
                    'drop_field:'.$name,
                    'column:'.$resource->table_name.'.'.$name,
                    'destructive',
                    ['persisted']
                );
            }
        }
        foreach (['indexes' => 'create_index', 'uniques' => 'create_unique'] as $kind => $prefix) {
            foreach ((array) (($schema['constraints'] ?? [])[$kind] ?? []) as $constraint) {
                $fields = array_values(array_filter((array) ($constraint['fields'] ?? [])));
                if ($fields !== []) {
                    $steps[] = $this->migrationStep($prefix.':'.($constraint['name'] ?? implode('_', $fields)), 'table:'.$resource->table_name, 'safe', ['constraint']);
                }
            }
        }
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            $generator = (array) (($field['extensions'] ?? [])['generator'] ?? []);
            if (strtolower((string) ($generator['type'] ?? '')) !== 'sequence' && strtolower((string) ($generator['type'] ?? '')) !== 'slug') {
                continue;
            }
            if (($generator['unique'] ?? true) === false) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $steps[] = $this->migrationStep('create_unique:'.$name, 'table:'.$resource->table_name, 'safe', ['constraint']);
            }
        }
        if ($active) {
            $afterConstraints = (array) (($schema['constraints'] ?? []));
            $beforeConstraints = (array) (($active->schema_json['constraints'] ?? []));
            foreach (['indexes' => 'index', 'uniques' => 'unique'] as $kind => $label) {
                $beforeMap = [];
                foreach ((array) ($beforeConstraints[$kind] ?? []) as $constraint) {
                    $key = (string) ($constraint['name'] ?? implode('_', (array) ($constraint['fields'] ?? [])));
                    $beforeMap[$key] = true;
                }
                $afterMap = [];
                foreach ((array) ($afterConstraints[$kind] ?? []) as $constraint) {
                    $key = (string) ($constraint['name'] ?? implode('_', (array) ($constraint['fields'] ?? [])));
                    $afterMap[$key] = true;
                }
                foreach (array_diff_key($beforeMap, $afterMap) as $name => $_) {
                    $steps[] = $this->migrationStep('drop_'.$label.':'.$name, 'table:'.$resource->table_name, 'destructive', ['constraint']);
                }
            }
        }
        $risk = 'safe';
        foreach ($steps as $step) {
            if ($this->riskRank((string) $step['risk']) > $this->riskRank($risk)) {
                $risk = $step['risk'];
            }
        }
        return ['required' => $steps !== [], 'risk' => $risk, 'destructive' => $risk === 'destructive', 'steps' => $steps];
    }

    /** @param list<string> $changes @return array{key:string,target:string,risk:string,changes:list<string>,destructive:bool} */
    private function migrationStep(string $key, string $target, string $risk, array $changes = []): array
    {
        return ['key' => $key, 'target' => $target, 'risk' => $risk, 'changes' => array_values($changes), 'destructive' => $risk === 'destructive'];
    }

    private function riskRank(string $risk): int
    {
        return ['safe' => 1, 'guarded' => 2, 'destructive' => 3][$risk] ?? 1;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function fieldStorageChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (['type', 'multiple', 'required', 'default'] as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changes[] = $key;
            }
        }
        $beforeSecret = (array) (($before['extensions'] ?? [])['secret'] ?? []);
        $afterSecret = (array) (($after['extensions'] ?? [])['secret'] ?? []);
        if (($beforeSecret['storage_mode'] ?? '') !== ($afterSecret['storage_mode'] ?? '')) {
            $changes[] = 'secret_storage';
        }
        return $changes;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param list<string> $changes */
    private function fieldStorageRisk(array $before, array $after, array $changes): string
    {
        if (in_array('multiple', $changes, true) || in_array('secret_storage', $changes, true) || (($before['type'] ?? '') !== ($after['type'] ?? ''))) {
            return 'destructive';
        }
        if (in_array('required', $changes, true) && empty($before['required']) && !empty($after['required'])) {
            return 'guarded';
        }
        return 'safe';
    }

    /**
     * 生成供审批界面展示的字段新增、删除和变更摘要，不改变实际 Schema 内容。
     *
     * @return array<string, mixed>
     */
    private function changeSummary(array $before, array $after): array
    {
        $old = [];
        foreach ((array) ($before['fields'] ?? []) as $field) {
            $old[$field['name']] = $field;
        }
        $created = [];
        $removed = [];
        $updated = [];
        foreach ((array) ($after['fields'] ?? []) as $field) {
            $name = $field['name'];
            if (!isset($old[$name])) {
                $created[] = $name;
            } else {
                $changes = $this->fieldChanges((array) $old[$name], (array) $field);
                if ($changes !== []) {
                    $updated[] = ['name' => $name, 'changes' => $changes];
                }
            }
            unset($old[$name]);
        }
        $removed = array_keys($old);
        sort($created);
        sort($removed);

        $beforeCapabilities = (array) ($before['capabilities'] ?? []);
        $afterCapabilities = (array) ($after['capabilities'] ?? []);
        $capabilityMap = [
            'allow_import' => 'capabilities.import',
            'allow_export' => 'capabilities.export',
            'allow_duplicate' => 'capabilities.duplicate',
            'allow_print' => 'capabilities.print',
            'allow_recycle' => 'capabilities.recycle',
            'allow_draft' => 'capabilities.draft',
            'track_mode' => 'capabilities.track_mode',
        ];
        $capabilityChanges = [];
        foreach ($capabilityMap as $key => $label) {
            if (($beforeCapabilities[$key] ?? null) !== ($afterCapabilities[$key] ?? null)) {
                $capabilityChanges[] = $label;
            }
        }
        if (($before['state'] ?? null) != ($after['state'] ?? null)) {
            $capabilityChanges[] = 'state';
        }

        $beforeActions = (array) ($before['actions'] ?? []);
        $afterActions = (array) ($after['actions'] ?? []);
        $actionNames = array_unique(array_merge(array_keys($beforeActions), array_keys($afterActions)));
        $actionChanges = [];
        foreach ($actionNames as $actionName) {
            if (($beforeActions[$actionName] ?? null) != ($afterActions[$actionName] ?? null)) {
                $actionChanges[] = 'actions.'.(string) $actionName;
            }
        }
        sort($actionChanges);

        return [
            'created_fields' => $created,
            'updated_fields' => $updated,
            'removed_fields' => $removed,
            'capability_changes' => $capabilityChanges,
            'action_changes' => $actionChanges,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function fieldChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ([
            'type', 'label', 'description', 'persisted', 'readonly', 'required',
            'multiple', 'default', 'extensions',
        ] as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changes[] = $key;
            }
        }
        return $changes;
    }
    private function assertChecksum(ResourceVersion $draft, string $expected, string $stage): void
    {
        if ($expected !== '' && $expected !== $draft->checksum) {
            throw $this->error('checksum_mismatch', 'draft checksum mismatch', $stage, ['expected_checksum' => $expected, 'current_checksum' => $draft->checksum]);
        }
    }
    private function taskInfo(PublishTask $task): PublishTaskInfo
    {
        return new PublishTaskInfo(['id' => (int) $task->id, 'resource' => $task->resource()->value('resource_key'), 'version' => ['version_id' => (int) $task->version_id], 'status' => $task->status, 'phase' => $task->phase, 'last_error_code' => $task->last_error_code, 'last_error_message' => $task->last_error_message, 'started_at' => $task->started_at, 'finished_at' => $task->finished_at, 'migration_result' => $task->migration_result_json, 'projection_result' => $task->projection_result_json]);
    }
    private function error(string $code, string $message, string $stage, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details, [], false, $stage);
    }
}
