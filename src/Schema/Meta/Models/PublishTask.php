<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema 发布任务及其迁移、投影结果快照。
 *
 * @property int $id
 * @property int $resource_id
 * @property int $version_id
 * @property string $status
 * @property string $phase
 * @property string $triggered_by
 * @property string $check_checksum
 * @property string $last_error_message 最后错误信息，尚未失败时为空字符串。
 * @property string $last_error_code
 * @property string $failure_detail_ref
 * @property array<string, mixed>|null $migration_result_json
 * @property array<string, mixed>|null $projection_result_json
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class PublishTask extends Model
{
    protected $table = 'schema_publish_tasks';
    public $timestamps = true;
    protected $guarded = [];
    protected $attributes = ['last_error_message' => ''];
    protected $casts = [
        'resource_id' => 'integer', 'version_id' => 'integer',
        'migration_result_json' => 'json', 'projection_result_json' => 'json',
    ];

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /** @return BelongsTo<ResourceVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'version_id');
    }
}
