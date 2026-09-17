<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 已发布字段的数据库投影。该表记录展示能力和物理列名，不替代版本中的字段定义。
 *
 * @property int $resource_id
 * @property int $version_id
 * @property string $name
 * @property string $column_name
 */
final class FieldProjection extends Model
{
    protected $table = 'schema_field_projections';
    public $timestamps = true;
    protected $guarded = [];
    protected $casts = [
        'resource_id' => 'integer', 'version_id' => 'integer', 'sort' => 'integer',
        'persisted' => 'boolean', 'multiple' => 'boolean', 'required' => 'boolean',
        'readonly' => 'boolean', 'disabled' => 'boolean', 'listable' => 'boolean',
        'formable' => 'boolean', 'searchable' => 'boolean', 'sortable' => 'boolean',
        'extensions' => 'json',
    ];
}
