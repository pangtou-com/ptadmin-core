<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Models\Admin;

final class AccountAuthorizationIdentityResolver implements AuthorizationIdentityResolverInterface
{
    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    public function resolve(Admin $admin): AuthorizationIdentity
    {
        $ids = $this->database->table('admin_user_roles as relations')
            ->join('admin_roles as roles', 'roles.id', '=', 'relations.role_id')
            ->where('relations.admin_id', $admin->getKey())
            ->where('roles.status', 1)->whereNull('roles.deleted_at')
            ->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all();

        return new AuthorizationIdentity($ids, superuser: (bool) $admin->getAttribute('is_founder'));
    }

    public function permits(Admin $admin, string $resourceKey, string $ability): bool
    {
        return true;
    }
}
