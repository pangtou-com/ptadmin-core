<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

final class RuntimeContributor
{
    public function __construct(
        private RuntimeRegistry $registry,
        private array $owner,
    ) {
    }

    public function resource(string $resourceKey): RuntimeResourceRegistration
    {
        return new RuntimeResourceRegistration($this->registry, $resourceKey, $this->owner);
    }
}
