<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;
use PTAdmin\Schema\Meta\Casts\SchemaJson;

/**
 * Schema 版本快照模型。schema_json 是协议正文，其他 JSON 字段保存校验、迁移和变更摘要结果。
 *
 * @property int $id
 * @property string $checksum
 * @property string $check_checksum
 * @property string $check_status
 * @property int $resource_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $schema_json
 * @property bool $allow_draft
 * @property string $draft_field
 * @property mixed $draft_value
 * @property array<string, mixed>|null $change_summary_json
 * @property array<string, mixed>|null $validation_result_json
 * @property array<string, mixed>|null $migration_plan_json
 * @property string $checked_by
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property string $activated_by
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ResourceVersion extends Model
{
    protected $table = 'schema_resource_versions';
    public $timestamps = true;
    protected $guarded = [];
    protected $casts = [
        'resource_id' => 'integer', 'version' => 'integer', 'allow_draft' => 'boolean',
        'draft_value' => 'json', 'schema_json' => SchemaJson::class, 'change_summary_json' => 'json',
        'validation_result_json' => 'json', 'migration_plan_json' => 'json',
    ];
}
