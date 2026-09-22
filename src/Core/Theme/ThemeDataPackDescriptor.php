<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

final class ThemeDataPackDescriptor
{
    /**
     * @param list<string> $requires
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $optional,
        public readonly array $requires,
        public readonly string $file,
        public readonly string $path,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'optional' => $this->optional,
            'requires' => $this->requires,
            'file' => $this->file,
        ];
    }
}
