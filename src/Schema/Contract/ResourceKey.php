<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

use JsonSerializable;

/**
 * Schema 资源的稳定引用键，支持带命名空间和不带命名空间两种形式。
 */
final class ResourceKey implements JsonSerializable
{
    private string $value;

    public function __construct(string $value = '')
    {
        $this->value = trim($value);
    }

    public static function resource(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isZero(): bool
    {
        return $this->value === '';
    }

    public function namespace(): string
    {
        return $this->parts()[0];
    }

    public function name(): string
    {
        return $this->parts()[1];
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /** @return array{0: string, 1: string} */
    private function parts(): array
    {
        if ($this->value === '') {
            return ['', ''];
        }

        $parts = explode('.', $this->value, 2);

        return count($parts) === 1 ? ['', $parts[0]] : [$parts[0], $parts[1]];
    }
}
