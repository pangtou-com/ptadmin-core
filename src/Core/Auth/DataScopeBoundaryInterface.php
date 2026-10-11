<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth;

use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contract\ServerConstraint;

/** 宿主的强制数据边界，与角色授予的数据范围取交集。 */
interface DataScopeBoundaryInterface
{
    /** @param array<string, mixed> $schema */
    public function resolve(Admin $admin, string $resourceKey, string $action, array $schema): ?ServerConstraint;
}
