<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use PTAdmin\Schema\Contract\ContractObject;

/**
 * 将数组或 DTO/请求对象转换为协议数组，统一服务层的输入边界。
 */
final class Payload
{
    /** @param mixed $value @return array<string, mixed> */
    public static function array(mixed $value): array
    {
        if ($value instanceof ContractObject) {
            return $value->toPayloadArray();
        }
        if ($value instanceof DataObject) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return $value;
        }

        throw new \TypeError('Protocol payload must be an array or data object.');
    }

    /**
     * 将 Laravel 常用数组输入立即转换为指定协议 DTO，确保未知字段和字段类型在服务入口被拒绝。
     *
     * @template T of ContractObject
     * @param class-string<T> $class
     * @return array<string, mixed>
     */
    public static function dto(array|object $value, string $class): array
    {
        if (!$value instanceof $class) {
            $value = new $class(self::array($value));
        }

        return $value->toPayloadArray();
    }

    /** @param mixed $value */
    public static function value(mixed $value, string $key, mixed $default = null): mixed
    {
        $array = self::array($value);
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
}
