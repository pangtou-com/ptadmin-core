<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 后台导航模型，独立于业务权限与授权记录。
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
 * @property string $entry_permission 页面入口业务权限
 * @property string $visibility 可见性规则
 * @property int $sort
 */
final class AdminMenu extends Model
{
    use SoftDeletes;

    protected $table = 'admin_menus';
    protected $guarded = [];
    protected $casts = ['meta_json' => 'array', 'is_menu' => 'boolean', 'status' => 'boolean', 'sort' => 'integer'];
}
