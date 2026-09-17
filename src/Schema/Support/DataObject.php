<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use JsonSerializable;

/**
 * 协议 DTO 的轻量基类。属性只从输入载荷产生，序列化时递归转换嵌套对象。
 */
abstract class DataObject implements JsonSerializable
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::convert($this->attributes);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param mixed $value */
    protected static function convert(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if ($value instanceof \JsonSerializable) {
            return self::convert($value->jsonSerialize());
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::convert($item);
            }
            return $result;
        }
        return $value;
    }

    /** @param array<string, mixed> $attributes */
    public static function fromArray(array $attributes): static
    {
        return new static($attributes);
    }
}
