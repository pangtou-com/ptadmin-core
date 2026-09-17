<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

use ArrayAccess;
use PTAdmin\Schema\Support\DataObject;

/**
 * 协议 DTO 基类，按子类声明的字段类型完成嵌套对象转换、未知字段检查和递归序列化。
 *
 * @implements ArrayAccess<string, mixed>
 */
abstract class ContractObject extends DataObject implements ArrayAccess
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [];

    /**
     * @param array<string, mixed> $attributes 协议输入属性。
     */
    public function __construct(array $attributes = [])
    {
        if (static::FIELD_TYPES === []) {
            parent::__construct($attributes);

            return;
        }

        $unknown = array_diff(array_keys($attributes), array_keys(static::FIELD_TYPES));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s contains unknown protocol fields: %s',
                static::class,
                implode(', ', $unknown),
            ));
        }

        $values = [];
        foreach ($attributes as $field => $value) {
            $options = static::FIELD_OPTIONS[$field] ?? [];
            $value = self::hydrate(static::FIELD_TYPES[$field], $value, $field, $options);
            $this->{$field} = $value;
            $values[$field] = $value;
        }

        parent::__construct($values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->serialize(true);
    }

    /**
     * 返回服务内部载荷。`json:"-"` 字段会保留，供可信宿主传递数据范围或存储配置。
     *
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        return $this->serialize(false);
    }

    /** @return array<string, mixed> */
    private function serialize(bool $protocol): array
    {
        $values = [];
        foreach (static::FIELD_TYPES as $field => $type) {
            $options = static::FIELD_OPTIONS[$field] ?? [];
            if ($protocol && ($options['serialized'] ?? true) === false) {
                continue;
            }

            $provided = array_key_exists($field, $this->attributes);
            if (!$provided && ($options['omit_empty'] ?? false)) {
                continue;
            }

            $value = property_exists($this, $field) ? $this->{$field} : ($this->attributes[$field] ?? null);
            if (($options['omit_empty'] ?? false) && self::isEmptyProtocolValue($value)) {
                continue;
            }

            if (!$provided && $value === null && !($options['nullable'] ?? false)) {
                $value = self::defaultValue($type);
            }
            $values[$field] = $value;
        }

        return self::convert($values);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->{$offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? ($this->{$offset} ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Protocol DTO values cannot be changed through array access.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Protocol DTO values cannot be removed through array access.');
    }

    /**
     * @param string|array<class-string>|class-string $type
     */
    private static function hydrate(string|array $type, mixed $value, string $field, array $options = []): mixed
    {
        if ($value === null && ($options['nullable'] ?? false)) {
            return null;
        }

        if (is_array($type)) {
            if ($value === null) {
                return [];
            }
            if (!is_array($value)) {
                throw new \TypeError(sprintf('%s.%s must be an array.', static::class, $field));
            }

            $itemType = $type[0];

            return array_map(
                static fn (mixed $item): mixed => self::hydrate($itemType, $item, $field),
                $value,
            );
        }

        if ($value === null) {
            return match ($type) {
                'string' => '',
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'array' => [],
                default => null,
            };
        }

        return match ($type) {
            'mixed' => $value,
            'string' => is_string($value) ? $value : throw new \TypeError(sprintf('%s.%s must be a string.', static::class, $field)),
            'int' => is_int($value) ? $value : throw new \TypeError(sprintf('%s.%s must be an integer.', static::class, $field)),
            'float' => is_float($value) || is_int($value) ? (float) $value : throw new \TypeError(sprintf('%s.%s must be a float.', static::class, $field)),
            'bool' => is_bool($value) ? $value : throw new \TypeError(sprintf('%s.%s must be a boolean.', static::class, $field)),
            'array' => is_array($value) ? $value : throw new \TypeError(sprintf('%s.%s must be an array.', static::class, $field)),
            ResourceKey::class => $value instanceof ResourceKey
                ? $value
                : (is_string($value)
                    ? new ResourceKey($value)
                    : throw new \TypeError(sprintf('%s.%s must be a resource key string.', static::class, $field))),
            default => self::hydrateObject($type, $value, $field),
        };
    }

    /** @param string|array<class-string>|class-string $type */
    private static function defaultValue(string|array $type): mixed
    {
        if (is_array($type)) {
            return [];
        }

        return match ($type) {
            'mixed' => null,
            'string' => '',
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'array' => [],
            ResourceKey::class => new ResourceKey(''),
            default => new $type(),
        };
    }

    private static function isEmptyProtocolValue(mixed $value): bool
    {
        if ($value instanceof ResourceKey) {
            return $value->isZero();
        }

        return $value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === [];
    }

    private static function hydrateObject(string $class, mixed $value, string $field): object
    {
        if ($value instanceof $class) {
            return $value;
        }
        if (is_array($value)) {
            return new $class($value);
        }

        throw new \TypeError(sprintf('%s.%s must be %s or an attribute array.', static::class, $field, $class));
    }
}
