<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Publish\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Meta\Models\{PublishTask, Resource};
use PTAdmin\Schema\Meta\Service\ResourceLocator;

/** 已停用字段的显式破坏性操作，复用发布任务互斥和失败修复标记。 */
final class RetiredFieldPurger
{
    public function __construct(private readonly ResourceLocator $locator)
    {
    }

    public function purge(string $key, string $name, string $confirmation, string $checksum, int $activeVersion, string $actor): array
    {
        if ($name === '' || $confirmation !== $name || $checksum === '' || $activeVersion < 1) {
            throw new SchemaError('invalid_input', '请输入完整字段编码并提供当前草稿和已发布版本。');
        }
        [$resource, $task] = DB::transaction(function () use ($key, $name, $checksum, $activeVersion, $actor): array {
            $resource = Resource::query()->where('resource_key', $key)->lockForUpdate()->firstOrFail();
            if (!in_array($resource->source_type, ['studio', 'custom'], true) || $resource->migration_owner === 'domain' || $resource->status === 'archived') {
                throw new SchemaError('resource_readonly', '当前模型不允许永久删除数据库字段。');
            }
            if ($resource->publish_repair_required) {
                throw new SchemaError('structure_repair_required', '请先修复上次发布的数据库结构。');
            }
            if (PublishTask::query()->where('resource_id', $resource->id)->where('status', 'running')->exists()) {
                throw new SchemaError('publish_in_progress', '模型正在发布，请稍后操作。');
            }
            $draft = $this->locator->draft($resource);
            $active = $this->locator->active($resource);
            if (!hash_equals($draft->checksum, $checksum) || (int) $active->id !== $activeVersion) {
                throw new SchemaError('checksum_mismatch', '草稿或已发布版本已变化，请重新加载后操作。');
            }
            $retired = $resource->retired_fields_json ?? [];
            if (!isset($retired[$name]) || in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', $active->schema_json['primaryKey'] ?? 'id'], true)) {
                throw new SchemaError('field_not_retired', '只能永久删除已发布停用的普通字段。');
            }
            $lifecycle = new FieldLifecycle();
            foreach ([$active->schema_json, $draft->schema_json] as $schema) {
                if (in_array($name, array_column($schema['fields'] ?? [], 'name'), true) || $lifecycle->references($schema, $name)) {
                    throw new SchemaError('field_still_referenced', '字段已恢复到草稿或仍被模型配置引用，请先移除引用并保存。');
                }
            }
            $structure = $lifecycle->structure($resource);
            if (($structure['columns'][$name] ?? null) != $retired[$name]['column']) {
                throw new SchemaError('field_storage_changed', '保留列已缺失或发生变化，请先核对数据库。');
            }
            foreach ([...$structure['indexes'], ...$structure['foreign_keys']] as $constraint) {
                if (in_array($name, $constraint['columns'], true)) {
                    throw new SchemaError('field_still_referenced', '字段仍被数据库索引或外键使用，请先处理约束。');
                }
            }
            if ($lifecycle->incomingReferences($resource, [$name], true) !== []) {
                throw new SchemaError('field_still_referenced', '字段仍被其他模型的已发布版本或草稿引用。');
            }
            $this->assertNoIncomingForeignKey($resource, $name);
            $task = PublishTask::query()->create([
                'resource_id' => $resource->id, 'version_id' => $active->id, 'status' => 'running',
                'phase' => 'migration', 'triggered_by' => $actor, 'check_checksum' => $checksum, 'started_at' => now(),
                'migration_result_json' => ['operation' => 'purge_retired_field', 'field' => $name, 'executed' => false],
            ]);
            return [$resource, $task];
        });
        try {
            // MySQL DDL 会隐式提交，不能把删列包在元数据事务里并假设它可回滚。
            DatabaseSchema::table($resource->table_name, static fn ($table) => $table->dropColumn($name));
            DB::transaction(function () use ($resource, $task, $name): void {
                $locked = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
                $retired = $locked->retired_fields_json;
                unset($retired[$name]);
                $locked->retired_fields_json = $retired;
                $locked->save();
                $locked->versions()->where('status', 'draft')->update([
                    'check_status' => 'none', 'check_checksum' => '', 'checked_at' => null,
                    'validation_result_json' => null, 'migration_plan_json' => null, 'change_summary_json' => null,
                ]);
                PublishTask::query()->where('resource_id', $resource->id)->where('status', 'pending')->update([
                    'status' => 'cancelled', 'finished_at' => now(), 'last_error_message' => '字段已永久删除，原审批失效。',
                ]);
                $task->update(['status' => 'succeeded', 'finished_at' => now(),
                    'migration_result_json' => ['operation' => 'purge_retired_field', 'field' => $name, 'executed' => true]]);
            });
        } catch (\Throwable $error) {
            $resource->update(['publish_repair_required' => true]);
            $task->update(['status' => 'failed', 'last_error_code' => 'field_purge_failed',
                'last_error_message' => $error->getMessage(), 'finished_at' => now()]);
            throw new SchemaError('field_purge_failed', '字段永久删除未完成，请核对任务和数据库结构：'.$error->getMessage());
        }
        return ['resource' => $key, 'field' => $name, 'deleted' => true, 'task_id' => $task->id];
    }

    /** 一次结构查询覆盖外部表，避免逐表查询及遗漏非 Schema 管理的数据库外键。 */
    private function assertNoIncomingForeignKey(Resource $resource, string $name): void
    {
        $connection = DB::connection();
        $table = $connection->getTablePrefix().$resource->table_name;
        $references = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->select('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?', [$connection->getDatabaseName(), $table, $name]),
            'sqlite' => $connection->select('SELECT m.name FROM sqlite_master AS m JOIN pragma_foreign_key_list(m.name) AS f WHERE m.type = ? AND f."table" = ? AND (f."to" = ? OR f."to" IS NULL)', ['table', $table, $name]),
            default => throw new SchemaError('unsupported_driver', '当前数据库暂不支持设计中心永久删除字段。'),
        };
        if ($references !== []) {
            throw new SchemaError('field_still_referenced', '字段仍被其他数据库表的外键引用。');
        }
    }
}
