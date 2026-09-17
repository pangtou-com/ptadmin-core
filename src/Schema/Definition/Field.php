<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

use PTAdmin\Schema\Support\Protocol;

/**
 * 字段定义归一化和序列化工具，负责保持字段默认值、显示能力及扩展配置的协议形状。
 */
final class Field
{
    /** @param array<string, mixed> $field */
    public static function normalize(array $field): array
    {
        $field['name'] = (string) ($field['name'] ?? '');
        $field['type'] = strtolower((string) ($field['type'] ?? 'string'));
        if (array_key_exists('persisted', $field) === false) {
            $field['persisted'] = true;
        }
        foreach (['formable', 'listable', 'searchable', 'sortable'] as $key) {
            if (array_key_exists($key, $field) && $field[$key] !== null) {
                $field[$key] = (bool) $field[$key];
            }
        }
        return $field;
    }

    /** @param array<string, mixed> $field */
    public static function serializeDefinition(array $field): array
    {
        $required = ['name', 'type', 'label'];
        $result = Protocol::withoutEmpty($field, array_merge($required, ['extensions']));
        // Go 使用 *bool 区分未声明与显式 false，这四个投影开关不能按空值裁剪。
        foreach (['formable', 'listable', 'searchable', 'sortable'] as $key) {
            if (array_key_exists($key, $field) && $field[$key] !== null) {
                $result[$key] = $field[$key];
            }
        }
        // persisted defaults to true while false marks virtual/runtime-managed fields.
        // It must survive empty-value pruning or a second normalization turns it back on.
        if (array_key_exists('persisted', $field)) {
            $result['persisted'] = (bool) $field['persisted'];
        }
        // Go 的 interface{} default 在 false、0 或空字符串时仍然是非 nil 值，必须保留。
        if (array_key_exists('default', $field) && $field['default'] !== null) {
            $result['default'] = $field['default'];
        }
        if (!array_key_exists('extensions', $result)) {
            $result['extensions'] = (object) [];
        }
        if (isset($result['extensions']) && is_array($result['extensions'])) {
            $result['extensions'] = Protocol::withoutEmpty((array) $result['extensions']);
        }
        return $result;
    }
}
