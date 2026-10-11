<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use PTAdmin\Admin\Models\Admin;

interface AuthorizationIdentityResolverInterface
{
    public function resolve(Admin $admin): AuthorizationIdentity;

    /** 身份级约束只收窄访问，实际允许仍由资源授权判定。 */
    public function permits(Admin $admin, string $resourceKey, string $ability): bool;
}
