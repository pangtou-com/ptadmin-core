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
