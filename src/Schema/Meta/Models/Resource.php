<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Schema 资源主表模型。resource_key 是跨版本稳定标识，active_version_id 指向当前运行版本。
 *
 * @property int $id
 * @property string $namespace
 * @property string $name
 * @property string $resource_key
 * @property string $migration_owner
 * @property string $title 资源显示名称。
 * @property string $description 资源业务说明，未填写时为空字符串。
 * @property string $source_type 资源定义来源。
 * @property string $owner_type 资源所属主体类型。
 * @property string $owner_code 资源所属主体编码。
 * @property string $table_name
 * @property string $design_flow_id
 * @property string $status
 * @property int $active_version_id
 * @property bool $allow_import
 * @property bool $allow_export
 * @property bool $allow_duplicate
 * @property bool $allow_print
 * @property bool $allow_recycle
 * @property bool $allow_draft
 * @property string $draft_field
 * @property mixed $draft_value
 * @property string $track_mode
 * @property string $created_by
 * @property string $updated_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Resource extends Model
{
    protected $table = 'schema_resources';
    public $timestamps = true;
    protected $guarded = [];
    protected $attributes = ['description' => ''];
    protected $casts = [
        'active_version_id' => 'integer',
        'allow_import' => 'boolean', 'allow_export' => 'boolean', 'allow_duplicate' => 'boolean',
        'allow_print' => 'boolean', 'allow_recycle' => 'boolean', 'allow_draft' => 'boolean',
        'draft_value' => 'json',
    ];

    /** @return HasMany<ResourceVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ResourceVersion::class, 'resource_id');
    }
}
