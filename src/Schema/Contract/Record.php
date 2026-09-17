<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

use JsonSerializable;

/**
 * Runtime 单条记录包装，区分转换后的值、原始值和关联展示标签。
 */
final class Record implements JsonSerializable
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values, private ?array $raw = null)
    {
        $this->raw ??= $values;
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function raw(string $field): mixed
    {
        return $this->raw[$field] ?? null;
    }

    public function label(string $field): mixed
    {
        return $this->values['_'.$field.'_label'] ?? null;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @return array<string, mixed> */
    public function rawArray(): array
    {
        return $this->raw ?? [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
