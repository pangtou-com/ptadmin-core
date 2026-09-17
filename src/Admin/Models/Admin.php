<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
