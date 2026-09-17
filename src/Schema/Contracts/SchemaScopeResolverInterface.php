<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Contract\ServerConstraint;

interface SchemaScopeResolverInterface
{
    public function resolve(mixed $subject, string $resourceKey, string $action): ?ServerConstraint;
}
