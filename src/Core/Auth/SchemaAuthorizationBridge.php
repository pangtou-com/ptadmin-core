<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth;

use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;

final class SchemaAuthorizationBridge implements SchemaAuthorizationInterface
{
    public function __construct(private AuthorizationService $authorization)
    {
    }

    public function allows(mixed $subject, string $resourceKey, string $action): bool
    {
        if (!$subject instanceof Admin) {
            return false;
        }

        return $this->authorization->allows($subject, $action, $resourceKey);
    }
}
