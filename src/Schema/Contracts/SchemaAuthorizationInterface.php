<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

interface SchemaAuthorizationInterface
{
    public function allows(mixed $subject, string $resourceKey, string $action): bool;
}
