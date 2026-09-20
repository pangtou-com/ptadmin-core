<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Schema 资源主表模型。resource_key 是跨版本稳定标识，active_version_id 指向当前运行版本。
 *
 * @property int $id
 * @property string $resource_key
 * @property string $table_name
 * @property string $design_flow_id
 * @property string $status
 * @property int|null $active_version_id
 */
final class Resource extends Model
{
    protected $table = 'schema_resources';
    public $timestamps = true;
    protected $guarded = [];
    protected $casts = [
        'active_version_id' => 'integer',
        'allow_import' => 'boolean', 'allow_export' => 'boolean', 'allow_duplicate' => 'boolean',
        'allow_print' => 'boolean', 'allow_recycle' => 'boolean', 'allow_draft' => 'boolean',
        'draft_value' => 'json',
    ];

    /** 返回资源的全部草稿和已发布版本。 */
    public function versions(): HasMany
    {
        return $this->hasMany(ResourceVersion::class, 'resource_id');
    }
}
