<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 后台权限资源模型。resource_key 同时用于服务端授权、菜单匹配和前端页面注册。
 *
 * @property string $type 资源类型，由资源同步或后台资源管理写入。
 */
final class AdminResource extends Model
{
    use SoftDeletes;

    protected $table = 'admin_resources';
    protected $guarded = [];
    protected $casts = ['meta_json' => 'array', 'is_menu' => 'boolean', 'status' => 'boolean', 'sort' => 'integer'];
}
