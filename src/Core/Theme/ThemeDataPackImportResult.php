<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

final class ThemeDataPackImportResult
{
    /** @param list<array<string,mixed>> $errors */
    public function __construct(
        public readonly string $status,
        public readonly int $created = 0,
        public readonly int $skipped = 0,
        public readonly int $failed = 0,
        public readonly array $errors = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
