<?php

declare(strict_types=1);

namespace PTAdmin\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Core 默认前台用户；插件通过统一用户接口使用，独立于后台管理员。
 *
 * @property int $id 用户标识
 * @property string $name 公开昵称
 * @property string $email 登录邮箱
 * @property string $password 密码哈希，不对外序列化
 * @property bool $status 账号是否启用
 * @property \Illuminate\Support\Carbon|null $email_verified_at 可信邮箱验证完成时间
 * @property \Illuminate\Support\Carbon|null $phone_verified_at 可信手机号验证完成时间
 * @property \Illuminate\Support\Carbon|null $realname_verified_at 可信实名验证完成时间
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> 状态与验证结果只能由宿主可信业务显式写入。 */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'realname_verified_at' => 'datetime',
        ];
    }
}
