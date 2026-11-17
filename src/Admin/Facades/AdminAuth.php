<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \PTAdmin\Admin\Models\Admin|null user()
 * @method static int|null id()
 * @method static bool check()
 * @method static \PTAdmin\Admin\Models\Admin authenticate()
 * @method static list<array{id:int,code:string,title:string}> roles()
 * @method static bool hasRole(string $code)
 * @method static bool anyRole(string|list<string> $codes)
 * @method static list<string> permissions()
 * @method static bool can(string $permission)
 * @method static bool hasPerm(string $permission)
 * @method static void authorize(string $permission)
 * @see \PTAdmin\Admin\Auth\AdminAuth
 */
final class AdminAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PTAdmin\Admin\Auth\AdminAuth::class;
    }
}
