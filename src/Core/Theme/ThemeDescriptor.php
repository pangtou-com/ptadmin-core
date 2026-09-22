<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

final class ThemeDescriptor
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public readonly string $code,
        public readonly string $version,
        public readonly string $directory,
        public readonly array $manifest,
        public readonly bool $isDefault = false,
    ) {
    }

    public function identifier(): string
    {
        return $this->code.'@'.$this->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'version' => $this->version,
            'directory' => $this->directory,
            'manifest' => $this->manifest,
            'default' => $this->isDefault,
        ];
    }
}
