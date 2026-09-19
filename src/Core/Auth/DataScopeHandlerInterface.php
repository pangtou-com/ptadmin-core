<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth;

use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contract\ServerConstraint;

interface DataScopeHandlerInterface
{
    public function type(): string;

    /** @param array<string, mixed>|list<mixed>|null $value */
    public function validate(?array $value): bool;

    /**
     * @param array<string, mixed>|list<mixed>|null $value
     * @param array<string, mixed> $schema
     */
    public function resolve(
        Admin $subject,
        string $resourceKey,
        string $action,
        ?array $value,
        array $schema,
    ): ?ServerConstraint;
}
