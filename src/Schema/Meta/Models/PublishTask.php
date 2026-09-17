<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema 发布任务及其迁移、投影结果快照。
 *
 * @property int $resource_id
 * @property int $version_id
 * @property string $status
 * @property string $phase
 * @property array<string, mixed>|null $migration_result_json
 * @property array<string, mixed>|null $projection_result_json
 */
final class PublishTask extends Model
{
    protected $table = 'schema_publish_tasks';
    public $timestamps = true;
    protected $guarded = [];
    protected $casts = [
        'resource_id' => 'integer', 'version_id' => 'integer',
        'migration_result_json' => 'json', 'projection_result_json' => 'json',
    ];

    /** 返回任务所属资源，便于查询发布历史。 */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /** 返回任务对应的 Schema 版本，用于查看发布输入和结果。 */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'version_id');
    }
}
