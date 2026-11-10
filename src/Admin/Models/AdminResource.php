<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 独立业务权限资源，resource_key 不随导航变化。
 *
 * @property int $id
 * @property string $resource_key
 * @property string $title
 * @property string $type 资源类型，由资源同步或后台资源管理写入。
 * @property string $parent_key
 * @property string $path
 * @property array<string, mixed>|null $meta_json
 * @property bool $is_menu
 * @property bool $status
 * @property int $sort
 */
final class AdminResource extends Model
{
    use SoftDeletes;

    protected $table = 'admin_resources';
    protected $guarded = [];
    protected $casts = ['meta_json' => 'array', 'is_menu' => 'boolean', 'status' => 'boolean', 'sort' => 'integer'];
}
