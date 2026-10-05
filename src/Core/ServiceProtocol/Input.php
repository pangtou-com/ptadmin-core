<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

final class Input
{
    public static function nonEmpty(string $value, string $field): void
    {
        if ($value === '' || trim($value) === '') {
            throw new ProtocolException('input_invalid', "协议字段 {$field} 不能为空。");
        }
    }

    public static function code(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $value) !== 1) {
            throw new ProtocolException('input_invalid', "协议编码 {$field} 无效。");
        }
    }

    public static function currency(string $value): void
    {
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new ProtocolException('input_invalid', '支付币种必须是三位大写 ISO 代码。');
        }
    }

    public static function url(string $value, string $field): void
    {
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true)
            || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            throw new ProtocolException('input_invalid', "协议字段 {$field} 必须是无内嵌凭据的 HTTP(S) 地址。");
        }
    }

    /** @param array<mixed> $values */
    public static function instances(array $values, string $class, string $field, bool $allowEmpty = false): void
    {
        if (!array_is_list($values) || (!$allowEmpty && $values === [])) {
            throw new ProtocolException('input_invalid', "协议列表 {$field} 无效或为空。");
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new ProtocolException('input_invalid', "协议列表 {$field} 的元素类型无效。");
            }
        }
    }

    /** @param array<mixed> $values */
    public static function codes(array $values, string $field, bool $allowEmpty = true): void
    {
        if (!array_is_list($values) || (!$allowEmpty && $values === [])) {
            throw new ProtocolException('input_invalid', "协议列表 {$field} 无效或为空。");
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ProtocolException('input_invalid', "协议列表 {$field} 的元素必须为编码字符串。");
            }
            self::code($value, $field);
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new ProtocolException('input_invalid', "协议列表 {$field} 包含重复编码。");
        }
    }

    /** @param array<mixed> $values OAuth scope-token 允许 URI 与冒号，不能套用渠道编码规则。 */
    public static function scopes(array $values): void
    {
        if (!array_is_list($values)) {
            throw new ProtocolException('input_invalid', 'OAuth scopes 必须为列表。');
        }
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $value) !== 1) {
                throw new ProtocolException('input_invalid', 'OAuth scope-token 格式无效。');
            }
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new ProtocolException('input_invalid', 'OAuth scopes 包含重复项。');
        }
    }


    /** @param array<mixed> $values PHP 数组不能声明元素类型，厂商表单和响应头须在边界检查。 */
    public static function stringMap(array $values, string $field): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                throw new ProtocolException('input_invalid', "协议字段 {$field} 必须为字符串键和值的映射。");
            }
        }
    }

}
