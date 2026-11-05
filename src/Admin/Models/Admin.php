<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 后台管理员账户。
 *
 * @property int $id
 * @property string $username
 * @property string $nickname
 * @property string $password
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $avatar
 * @property bool $status
 * @property bool $is_founder
 * @property \Illuminate\Support\Carbon|null $login_at
 * @property string|null $login_ip
 */
final class Admin extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'admins';

    protected $hidden = ['password'];

    protected $fillable = [
        'username',
        'nickname',
        'email',
        'mobile',
        'avatar',
        'status',
        'is_founder',
        'login_at',
        'login_ip',
    ];

    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'is_founder' => 'boolean',
        'login_at' => 'datetime',
    ];
}
