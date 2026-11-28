<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Publish\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Definition\Schema;

/** 发布计划中的字段删除、原列恢复和数据库约束检查。只在显式设计与发布流程读取结构。 */
final class FieldLifecycle
{
    public function structure(Resource $resource): array
    {
        if (!DatabaseSchema::hasTable($resource->table_name)) {
            return ['columns' => [], 'indexes' => [], 'foreign_keys' => []];
        }
        return [
            'columns' => array_column(DatabaseSchema::getColumns($resource->table_name), null, 'name'),
            'indexes' => DatabaseSchema::getIndexes($resource->table_name),
            'foreign_keys' => DatabaseSchema::getForeignKeys($resource->table_name),
        ];
    }

    public function issues(Resource $resource, array $before, array $after, array $plan): array
    {
        if ($resource->publish_repair_required) {
            return [$this->issue('structure_repair_required', '$', '上次发布已改变数据库结构，请修复后再发布；重置草稿不会修复数据库。')];
        }
        if ($resource->migration_owner === 'domain') {
            return [];
        }
        $structure = $this->structure($resource);
        $columns = $structure['columns'];
        $old = array_column($before['fields'] ?? [], null, 'name');
        $fields = array_column($after['fields'] ?? [], null, 'name');
        $retired = $resource->retired_fields_json ?? [];
        $issues = [];
        $needsBackfill = [];
        $requiring = [];
        $retiring = array_filter($old, static fn (array $field): bool => !empty($field['persisted']) && !isset($fields[$field['name']]));
        $incoming = $retiring === [] ? [] : $this->incomingReferences($resource, array_keys($retiring), true);
        $foreignReferences = $retiring === [] ? [] : $this->incomingForeignKeys($resource, array_keys($retiring));
        foreach ($plan['steps'] ?? [] as $step) {
            [$operation, $name] = array_pad(explode(':', $step['key'], 2), 2, '');
            if ($operation === 'alter_field') {
                if (($step['changes'] ?? []) !== ['required'] || in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', $before['primaryKey'] ?? 'id'], true)) {
                    $issues[] = $this->issue('unsupported_field_change', 'fields.'.$name,
                        sprintf('字段 %s 的存储配置暂不支持修改（%s → %s；变更项：%s），请恢复已发布配置。', $name, $old[$name]['type'] ?? '', $fields[$name]['type'] ?? '', implode('、', $step['changes'] ?? [])));
                } elseif (!empty($fields[$name]['required']) && isset($columns[$name])) {
                    $requiring[] = $name;
                }
            }
            if ($operation === 'add_field' && ($step['risk'] ?? '') === 'guarded') {
                $needsBackfill[] = $name;
            }
            if ($operation === 'restore_field' && !empty($fields[$name]['required']) && isset($columns[$name])) {
                $requiring[] = $name;
            }
            if (in_array($operation, ['drop_field', 'retire_field'], true)) {
                if (isset($fields[$name])) {
                    $issues[] = $this->issue('field_storage_protected', 'fields.'.$name, '已发布字段不能改为非持久化字段，如需永久删除，请移除字段定义后检查并发布。');
                    continue;
                }
                if ($resource->source_type === 'code' || in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', $before['primaryKey'] ?? 'id'], true)) {
                    $issues[] = $this->issue('field_storage_protected', 'fields.'.$name, '系统或代码来源字段不能通过模型发布删除。');
                    continue;
                }
                $column = $columns[$name] ?? null;
                if ($column === null) {
                    $issues[] = $this->issue('field_storage_missing', 'fields.'.$name, '原数据库列不存在，请先核对数据库结构。');
                }
                foreach ($structure['indexes'] as $index) {
                    if (in_array($name, $index['columns'], true)) {
                        $issues[] = $this->issue('field_deletion_blocked', 'fields.'.$name, '字段仍被数据库索引或唯一约束使用，暂不支持删除。');
                        break;
                    }
                }
                foreach ($structure['foreign_keys'] as $foreign) {
                    if (in_array($name, $foreign['columns'], true)) {
                        $issues[] = $this->issue('field_deletion_blocked', 'fields.'.$name, '字段仍被外键约束使用，暂不支持删除。');
                        break;
                    }
                }
                if ($this->references($after, $name)) {
                    $issues[] = $this->issue('field_still_referenced', 'fields.'.$name, '字段仍被视图、约束、树、数据范围或扩展配置引用，请先处理引用。');
                }
                if (isset($foreignReferences[$name])) {
                    $issues[] = $this->issue('field_still_referenced', 'fields.'.$name, '字段仍被其他数据库表的外键引用：'.implode('、', $foreignReferences[$name]));
                }
                if (isset($incoming[$name])) {
                    $issues[] = $this->issue('field_still_referenced', 'fields.'.$name, '字段被其他模型的已发布版本或草稿引用：'.implode('、', $incoming[$name]));
                }
            }
            if ($operation === 'drop_system_column') {
                $issues[] = $this->issue('system_column_protected', 'capabilities.allow_recycle', '关闭已发布的回收站会删除恢复标记，本期不支持此操作。');
            }
        }
        if ($requiring !== []) {
            $query = DB::table($resource->table_name);
            $grammar = DB::connection()->getQueryGrammar();
            foreach ($requiring as $index => $name) {
                $query->selectRaw('MAX(CASE WHEN '.$grammar->wrap($name).' IS NULL THEN 1 ELSE 0 END) AS '.$grammar->wrap('missing_'.$index));
            }
            $missing = (array) $query->first();
            foreach ($requiring as $index => $name) {
                if (!empty($missing['missing_'.$index])) {
                    $issues[] = $this->issue('field_backfill_required', 'fields.'.$name, '已有记录包含空值，请先回填数据，再将字段设为必填。');
                }
            }
        }
        if ($needsBackfill !== [] && $columns !== [] && DB::table($resource->table_name)->exists()) {
            foreach ($needsBackfill as $name) {
                $issues[] = $this->issue('field_backfill_required', 'fields.'.$name, '已有记录，新增必填字段需要默认值或先完成数据回填。');
            }
        }
        foreach ($retired as $name => $entry) {
            if (!isset($fields[$name])) {
                continue;
            }
            if ((new Schema(['fields' => [$fields[$name]]]))->checksum() !== (new Schema(['fields' => [$entry['definition']]]))->checksum()) {
                $issues[] = $this->issue('retired_field_reserved', 'fields.'.$name, '该编码属于已停用字段，请先恢复原定义并发布，再调整展示配置。');
            }
            if (($columns[$name] ?? null) != $entry['column']) {
                $issues[] = $this->issue('field_storage_changed', 'fields.'.$name, '保留列已缺失或结构发生变化，不能无损恢复；请先修复数据库。');
            }
        }
        foreach ($old as $name => $field) {
            if (!empty($field['persisted']) && !isset($columns[$name])) {
                $issues[] = $this->issue('field_storage_missing', 'fields.'.$name, '已发布字段的数据库列缺失，请先修复数据库。');
            }
        }
        return $issues;
    }

    /** 一次读取当前定义，在内存中检查跨模型选项、镜像和克隆字段引用。 */
    public function incomingReferences(Resource $resource, array $names, bool $includeDrafts = false): array
    {
        $versions = DB::table('schema_resource_versions as v')->join('schema_resources as r', 'r.id', '=', 'v.resource_id')
            ->where('r.id', '!=', $resource->id)
            ->where(function ($query) use ($includeDrafts): void {
                $query->whereColumn('r.active_version_id', 'v.id');
                if ($includeDrafts) {
                    $query->orWhere('v.status', 'draft');
                }
            })->get(['r.resource_key', 'v.schema_json']);
        $references = [];
        foreach ($versions as $version) {
            $schema = json_decode($version->schema_json, true, 512, JSON_THROW_ON_ERROR);
            foreach ($schema['fields'] ?? [] as $field) {
                if (($field['reference']['resource'] ?? '') !== $resource->resource_key) {
                    continue;
                }
                foreach ($names as $name) {
                    if ($this->references($field['reference'], $name)
                        || ($field['extensions']['mirror']['source_field'] ?? '') === $name
                        || ($field['extensions']['clone']['source_field'] ?? '') === $name) {
                        $references[$name][] = $version->resource_key.'.'.$field['name'];
                    }
                }
            }
        }
        return $references;
    }

    /** 一次查询检查所有待删字段的入向外键，包括非 Schema 管理的数据表。 */
    public function incomingForeignKeys(Resource $resource, array $names): array
    {
        $connection = DB::connection();
        $table = $connection->getTablePrefix().$resource->table_name;
        $rows = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->select('SELECT TABLE_NAME AS source_table, REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?', [$connection->getDatabaseName(), $table]),
            'sqlite' => $connection->select('SELECT m.name AS source_table, f."to" AS target_column FROM sqlite_master AS m JOIN pragma_foreign_key_list(m.name) AS f WHERE m.type = ? AND f."table" = ?', ['table', $table]),
            default => throw new \PTAdmin\Schema\Contract\SchemaError('unsupported_driver', '当前数据库暂不支持设计中心永久删除字段。'),
        };
        $references = [];
        foreach ($rows as $row) {
            foreach ($names as $name) {
                if ($row->target_column === $name || $row->target_column === null) {
                    $references[$name][] = $row->source_table;
                }
            }
        }
        return $references;
    }

    /** 迁移完成后记录实际保留列结构，激活版本时与字段投影一起提交。 */
    public function nextRetiredFields(Resource $resource, array $before, array $after, int $version): array
    {
        $retired = $resource->retired_fields_json ?? [];
        if ($resource->migration_owner === 'domain') {
            return $retired;
        }
        $fields = array_column($after['fields'] ?? [], null, 'name');
        $columns = $this->structure($resource)['columns'];
        foreach ($before['fields'] ?? [] as $field) {
            if (!empty($field['persisted']) && !isset($fields[$field['name']]) && isset($columns[$field['name']])) {
                $retired[$field['name']] = ['definition' => $field, 'retired_version' => $version, 'column' => $columns[$field['name']]];
            }
        }
        return array_diff_key($retired, $fields);
    }

    /** 发布检查宁可明确报告引用，不能在删除时偷偷修改插件配置。 */
    public function references(mixed $value, string $name): bool
    {
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $key => $child) {
                if ($key === 'name' || $key === 'title' || $key === 'description' || $key === 'label') {
                    continue;
                }
                if ($this->references($child, $name)) {
                    return true;
                }
            }
            return false;
        }
        return $value === $name || (is_string($value)
            && preg_match('/(?:\b(?:form|row|record)\.'.preg_quote($name, '/').'\b|\$\{'.preg_quote($name, '/').'\})/', $value) === 1);
    }

    private function issue(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }
}
