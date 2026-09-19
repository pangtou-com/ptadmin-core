<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 后台角色模型。code 是稳定授权标识，系统角色不能通过管理资源删除。
 */
final class AdminRole extends Model
{
    use SoftDeletes;

    protected $table = 'admin_roles';
    protected $guarded = [];
    protected $casts = [
        'status' => 'boolean',
        'is_system' => 'boolean',
        'sort' => 'integer',
    ];
}
