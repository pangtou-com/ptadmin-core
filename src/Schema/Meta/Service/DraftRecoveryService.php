<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Service;

use Illuminate\Support\Facades\DB;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Meta\Models\PublishTask;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Definition\Schema;

/** 管理端的草稿恢复信息与重置，不承担业务数据回滚。 */
final class DraftRecoveryService
{
    public function __construct(private readonly ResourceLocator $locator)
    {
    }

    public function info(string $key): array
    {
        $resource = $this->locator->resource($key);
        $active = $resource->versions()->where('status', 'active')->first();
        return [
            'active_version' => $active ? $this->locator->versionInfo($active)->toArray() : null,
            'active_schema' => $active ? (new Schema($active->schema_json))->toArray() : null,
            'retired_fields' => array_values(array_map(static fn (array $entry): array => [
                'definition' => $entry['definition'], 'retired_version' => $entry['retired_version'],
            ], $resource->retired_fields_json ?? [])),
            'repair_required' => (bool) $resource->publish_repair_required,
        ];
    }

    public function reset(string $key, string $expectedChecksum, int $expectedActiveVersion): array
    {
        return DB::transaction(function () use ($key, $expectedChecksum, $expectedActiveVersion): array {
            $resource = Resource::query()->where('resource_key', $key)->lockForUpdate()->firstOrFail();
            if (!in_array($resource->source_type, ['studio', 'custom'], true) || $resource->status === 'archived') {
                throw new SchemaError('resource_readonly', '当前模型不允许重置草稿。');
            }
            if (PublishTask::query()->where('resource_id', $resource->id)->where('status', 'running')->exists()) {
                throw new SchemaError('publish_in_progress', '模型正在发布，请稍后重置草稿。');
            }
            $draft = $this->locator->draft($resource);
            $active = $this->locator->active($resource);
            if ($expectedChecksum === '' || !hash_equals($draft->checksum, $expectedChecksum) || $expectedActiveVersion !== (int) $active->id) {
                throw new SchemaError('checksum_mismatch', '草稿或已发布版本已变化，请重新加载后操作。');
            }
            PublishTask::query()->where('version_id', $draft->id)->where('status', 'pending')->update([
                'status' => 'cancelled', 'finished_at' => now(), 'last_error_message' => '草稿重置，原审批失效。',
            ]);
            $draft->fill([
                'schema_json' => $active->schema_json, 'checksum' => $active->checksum,
                'check_status' => 'none', 'check_checksum' => '', 'checked_at' => null,
                'validation_result_json' => null, 'migration_plan_json' => null, 'change_summary_json' => null,
            ])->save();
            return [
                'resource' => $key, 'version' => $this->locator->versionInfo($draft)->toArray(),
                'schema' => (new Schema($draft->schema_json))->toArray(),
                'repair_required' => (bool) $resource->publish_repair_required,
            ];
        });
    }
}
