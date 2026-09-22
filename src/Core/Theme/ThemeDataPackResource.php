<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

final class ThemeDataPackResource
{
    /**
     * @param array<string,string> $fields
     */
    public function __construct(
        public readonly string $resourceKey,
        public readonly string $keyField,
        public readonly array $fields,
    ) {
    }
}
