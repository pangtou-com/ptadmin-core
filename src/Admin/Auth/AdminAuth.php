<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory;
use LogicException;
use PTAdmin\Admin\Models\Admin;

/** 当前后台身份的公共入口；业务角色和权限始终由可替换的授权身份解析器裁决。 */
final class AdminAuth
{
    /** @param Closure():AuthorizationService $authorization */
    public function __construct(private readonly Factory $auth, private readonly Closure $authorization)
    {
    }

    public function user(): ?Admin { return $this->guard()->currentAdmin(); }
    public function id(): ?int { return $this->guard()->id(); }
    public function check(): bool { return $this->guard()->check(); }
    public function authenticate(): Admin { return $this->guard()->authenticate(); }

    /** @return list<array{id:int,code:string,title:string}> */
    public function roles(): array
    {
        $admin = $this->user();
        return $admin === null ? [] : ($this->authorization)()->roleSummaries($admin);
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, array_column($this->roles(), 'code'), true);
    }

    /** @param string|list<string> $codes 任意一个有效角色匹配即可，空列表返回 false。 */
    public function anyRole(string|array $codes): bool
    {
        $codes = is_string($codes) ? [$codes] : $codes;
        if ($codes === []) {
            return false;
        }
        $roles = array_column($this->roles(), 'code');
        foreach ($codes as $code) {
            if (in_array($code, $roles, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $admin = $this->user();
        return $admin === null ? [] : ($this->authorization)()->permissions($admin);
    }

    public function can(string $permission): bool
    {
        [$resource, $ability] = PermissionName::parse($permission);
        $admin = $this->user();
        return $admin !== null && ($this->authorization)()->allows($admin, $ability, $resource);
    }

    /** can 的权限语义别名，接收完整的资源编码.操作编码。 */
    public function hasPerm(string $permission): bool
    {
        return $this->can($permission);
    }

    public function authorize(string $permission): void
    {
        [$resource, $ability] = PermissionName::parse($permission);
        $admin = $this->authenticate();
        if (!($this->authorization)()->allows($admin, $ability, $resource)) {
            throw new AuthorizationException('当前账号无权执行此操作。');
        }
    }

    public function guard(): AdminSessionGuard
    {
        $guard = $this->auth->guard('ptadmin');
        if (!$guard instanceof AdminSessionGuard) {
            throw new LogicException('请在 config/auth.php 为 ptadmin Guard 配置 ptadmin-session 驱动。');
        }
        return $guard;
    }
}
