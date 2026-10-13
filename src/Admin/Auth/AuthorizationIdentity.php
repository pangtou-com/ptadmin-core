<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/** 一次请求使用的授权身份；登录账号与业务任职可以拥有不同的角色来源。 */
final class AuthorizationIdentity
{
    /** @param list<int> $roleIds @param array<string, mixed> $attributes */
    public function __construct(
        public readonly array $roleIds,
        public readonly bool $directGrants = true,
        public readonly bool $superuser = false,
        public readonly array $attributes = [],
        /** 读取直接授权时使用的有效授权主体；为空时使用当前登录账号。 */
        public readonly ?int $authorizationSubjectId = null,
    ) {
    }
}
