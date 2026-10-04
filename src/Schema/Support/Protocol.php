<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use JsonException;

/**
 * 协议规范化和 JSON 编码工具，复现 Go encoding/json 的键排序及 HTML 字符转义规则。
 */
final class Protocol
{
    /** JSON 对象转为协议数组，保留任意配置中的空对象与空数组区别。 */
    public static function decode(string $json): mixed
    {
        return self::decodedValue(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function decodedValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }
            return array_map([self::class, 'decodedValue'], $properties);
        }
        if (is_array($value)) {
            return array_map([self::class, 'decodedValue'], $value);
        }
        return $value;
    }

    /** @param mixed $value */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof DataObject) {
            return self::normalize($value->toArray());
        }
        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::normalize($item);
            }
            return $result;
        }
        return $value;
    }

    /**
     * Go encoding/json 对 map key 使用字典序。PHP 入口统一递归排序对象键，
     * 保证相同 Schema 在不同语言中得到相同 checksum。
     *
     * @param mixed $value
     * @return mixed
     */
    public static function canonicalize(mixed $value): mixed
    {
        $value = self::normalize($value);
        if (!is_array($value)) {
            return $value;
        }

        $isList = self::isList($value);
        $result = [];
        if ($isList) {
            foreach ($value as $item) {
                $result[] = self::canonicalize($item);
            }
            return $result;
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $result[$key] = self::canonicalize($value[$key]);
        }
        return $result;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function encode(mixed $value): string
    {
        try {
            $encoded = json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode schema protocol JSON.', 0, $exception);
        }

        // encoding/json escapes HTML-sensitive bytes even when Unicode remains UTF-8.
        return str_replace(['<', '>', '&', "\u{2028}", "\u{2029}"], ['\\u003c', '\\u003e', '\\u0026', '\\u2028', '\\u2029'], $encoded);
    }

    /**
     * @param mixed $schema
     * @return string
     */
    public static function checksum(mixed $schema): string
    {
        return hash('sha256', self::encode($schema));
    }

    /**
     * 按 Go definition.Schema 的结构体字段顺序编码。Go 只会排序 map 键，
     * 不能把结构体字段整体改成字典序，否则跨语言 checksum 会不同。
     *
     * @param array<string, mixed> $schema
     * @return string
     * @throws JsonException
     */
    public static function schemaChecksum(array $schema): string
    {
        return hash('sha256', self::schemaJson($schema));
    }

    /** @param array<string, mixed> $schema */
    public static function schemaJson(array $schema): string
    {
        return self::encodeOrdered(self::orderedSchema($schema));
    }

    /**
     * Schema JSON 和 checksum 必须共享同一字段排序规则，避免两个出口产生不同的协议字节。
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function orderedSchema(array $schema): array
    {
        $ordered = [];
        foreach (['title', 'name', 'description', 'features', 'capabilities', 'state', 'tree', 'constraints', 'views', 'fields', 'actions', 'extensions'] as $key) {
            if (!array_key_exists($key, $schema)) {
                continue;
            }
            $value = $schema[$key];
            if ($key === 'features' || $key === 'actions' || $key === 'extensions') {
                $value = self::sortMap($value);
            }
            if ($key === 'capabilities') {
                $value = self::ordered($value, ['allow_import', 'allow_export', 'allow_duplicate', 'allow_print', 'allow_recycle', 'soft_delete', 'allow_draft', 'track_mode']);
            }
            if ($key === 'state') {
                $value = self::ordered($value, ['field', 'default_value', 'values']);
            }
            if ($key === 'tree') {
                $value = self::ordered($value, ['enabled', 'key_field', 'parent_field', 'order_field']);
            }
            if ($key === 'constraints') {
                $value = self::ordered($value, ['indexes', 'uniques']);
                if (is_array($value)) {
                    foreach (['indexes', 'uniques'] as $listKey) {
                        if (isset($value[$listKey])) {
                            $value[$listKey] = array_map(static fn ($item): array => self::ordered((array) $item, ['name', 'fields']), $value[$listKey]);
                        }
                    }
                }
            }
            if ($key === 'views') {
                $value = self::ordered($value, ['page', 'pages', 'search', 'searches', 'table', 'tables', 'form', 'forms', 'detail', 'details']);
            }
            if ($key === 'fields') {
                $value = array_map([self::class, 'orderedField'], (array) $value);
            }
            if ($key === 'actions') {
                $actions = [];
                foreach ((array) $value as $actionKey => $action) {
                    $actions[$actionKey] = self::orderedAction((array) $action);
                }
                $value = $actions;
            }
            $ordered[$key] = $value;
        }
        return $ordered;
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private static function orderedField(array $field): array
    {
        $ordered = self::ordered($field, ['name', 'type', 'label', 'aliases', 'description', 'persisted', 'readonly', 'disabled', 'formable', 'listable', 'searchable', 'sortable', 'required', 'multiple', 'default', 'default_expr', 'computed_expr', 'computed_triggers', 'reference', 'options', 'validation', 'normalize', 'display', 'extensions']);
        // Go's definition.Field uses `json:"persisted,omitempty"`: an explicit
        // false remains meaningful in the in-memory definition, but is omitted
        // from protocol JSON and therefore from the cross-language checksum.
        if (array_key_exists('persisted', $ordered) && $ordered['persisted'] === false) {
            unset($ordered['persisted']);
        }
        if (isset($ordered['default_expr'])) {
            $ordered['default_expr'] = self::expression((array) $ordered['default_expr'], true);
        }
        if (isset($ordered['computed_expr'])) {
            $ordered['computed_expr'] = self::expression((array) $ordered['computed_expr'], false);
        }
        if (isset($ordered['reference'])) {
            $ordered['reference'] = self::ordered((array) $ordered['reference'], ['resource', 'value_field', 'label_field']);
        }
        if (isset($ordered['options'])) {
            $ordered['options'] = self::options((array) $ordered['options']);
        }
        if (isset($ordered['validation'])) {
            $ordered['validation'] = array_map(static function ($rule): array {
                $rule = self::ordered((array) $rule, ['type', 'value', 'value_expr', 'message', 'when', 'params']);
                if (isset($rule['value_expr'])) {
                    $rule['value_expr'] = self::expression((array) $rule['value_expr'], true);
                }
                if (isset($rule['when'])) {
                    $rule['when'] = self::condition((array) $rule['when']);
                }
                if (isset($rule['params'])) {
                    $rule['params'] = self::sortMap($rule['params']);
                }
                return $rule;
            }, $ordered['validation']);
        }
        if (isset($ordered['normalize'])) {
            $ordered['normalize'] = self::ordered((array) $ordered['normalize'], ['trim', 'empty_as_null', 'case', 'accepted_formats', 'timezone']);
        }
        if (isset($ordered['display'])) {
            $ordered['display'] = self::ordered((array) $ordered['display'], ['formatter', 'format', 'timezone', 'true_text', 'false_text', 'precision', 'thousand_separator', 'prefix', 'suffix']);
        }
        if (isset($ordered['extensions'])) {
            $extensions = [];
            $rawExtensions = (array) $ordered['extensions'];
            foreach (['generator', 'mirror', 'clone', 'secret', 'mask'] as $key) {
                if (array_key_exists($key, $rawExtensions)) {
                    $extensions[$key] = self::extension($key, (array) $rawExtensions[$key]);
                }
            }
            $ordered['extensions'] = $extensions === [] ? (object) [] : $extensions;
        }
        return $ordered;
    }

    /** @param array<string, mixed> $extension @return array<string, mixed> */
    private static function extension(string $name, array $extension): mixed
    {
        $keys = match ($name) {
            'generator' => ['type', 'source_field', 'pattern', 'unique', 'on', 'retry_limit', 'allow_override', 'sequence', 'slug'], 'mirror', 'clone' => ['from_field', 'source_field'], 'secret' => ['storage_mode', 'readable', 'exportable'], 'mask' => ['type', 'pattern'], default => array_keys($extension)
        };
        $extension = self::ordered($extension, $keys);
        if (in_array($name, ['secret', 'mask'], true) && is_array($extension)) {
            foreach (['readable', 'exportable'] as $key) {
                if (array_key_exists($key, $extension) && $extension[$key] === false) {
                    unset($extension[$key]);
                }
            }
        }
        if ($name === 'generator' && is_array($extension) && array_key_exists('allow_override', $extension) && $extension['allow_override'] === false) {
            unset($extension['allow_override']);
        }
        if ($name === 'generator' && isset($extension['slug']) && is_array($extension['slug']) && array_key_exists('lowercase', $extension['slug']) && $extension['slug']['lowercase'] === false) {
            unset($extension['slug']['lowercase']);
        }
        if (isset($extension['sequence'])) {
            $extension['sequence'] = self::ordered((array) $extension['sequence'], ['start', 'step', 'pad']);
        }
        if (isset($extension['slug'])) {
            $extension['slug'] = self::ordered((array) $extension['slug'], ['transliteration', 'separator', 'lowercase']);
        }
        return $extension === [] ? (object) [] : $extension;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private static function options(array $options): array
    {
        $options = self::ordered($options, ['mode', 'value_policy', 'items', 'dictionary', 'reference', 'remote']);
        if (isset($options['items'])) {
            $options['items'] = array_map(static fn ($item): array => self::optionItem((array) $item), $options['items']);
        }
        if (isset($options['dictionary'])) {
            $options['dictionary'] = self::ordered((array) $options['dictionary'], ['code']);
        }
        if (isset($options['reference'])) {
            $options['reference'] = self::optionReference((array) $options['reference']);
        }
        if (isset($options['remote'])) {
            $options['remote'] = self::optionRemote((array) $options['remote']);
        }
        return $options;
    }
    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function optionItem(array $item): array
    {
        $item = self::ordered($item, ['label', 'value', 'disabled', 'description', 'group', 'children', 'meta']);
        if (isset($item['group'])) {
            $item['group'] = self::ordered((array) $item['group'], ['value', 'label']);
        }
        if (isset($item['children'])) {
            $item['children'] = array_map([self::class, 'optionItem'], $item['children']);
        }
        if (isset($item['meta'])) {
            $item['meta'] = self::sortMap($item['meta']);
        }
        return $item;
    }
    /** @param array<string, mixed> $reference @return array<string, mixed> */
    private static function optionReference(array $reference): array
    {
        return self::optionQuery($reference, ['disabled_field', 'parent_field', 'group_field', 'group_label_field', 'description_field', 'meta_fields', 'search_field', 'filters', 'sorts', 'limit', 'load_mode', 'params']);
    }
    /** @param array<string, mixed> $remote @return array<string, mixed> */
    private static function optionRemote(array $remote): array
    {
        return self::optionQuery($remote, ['target', 'url', 'provider', 'key', 'method', 'value_field', 'label_field', 'disabled_field', 'parent_field', 'group_field', 'group_label_field', 'description_field', 'meta_fields', 'keyword_param', 'filters', 'sorts', 'limit', 'load_mode', 'params']);
    }

    /**
     * 统一 Reference 与 Remote Options 的查询参数排序规则。
     *
     * @param array<string, mixed> $query
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function optionQuery(array $query, array $keys): array
    {
        $query = self::ordered($query, $keys);
        if (isset($query['filters'])) {
            $query['filters'] = array_map(static fn ($item): array => self::ordered((array) $item, ['field', 'operator', 'value', 'from']), $query['filters']);
        }
        if (isset($query['sorts'])) {
            $query['sorts'] = array_map(static fn ($item): array => self::ordered((array) $item, ['field', 'order']), $query['sorts']);
        }
        if (isset($query['params'])) {
            $query['params'] = self::sortMap($query['params']);
        }
        return $query;
    }
    /** @param array<string, mixed> $expression @return array<string, mixed> */
    private static function expression(array $expression, bool $fieldExpression): array
    {
        $keys = $fieldExpression ? ['type', 'value', 'offset', 'format', 'timezone'] : ['type', 'value', 'field', 'path', 'template', 'items', 'fields', 'separator', 'offset', 'format', 'timezone'];
        $expression = self::ordered($expression, $keys);
        if (isset($expression['offset'])) {
            $expression['offset'] = self::ordered((array) $expression['offset'], ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds']);
        }
        if (isset($expression['items'])) {
            $expression['items'] = array_map(static fn ($item): array => self::expression((array) $item, false), $expression['items']);
        }
        return $expression;
    }
    /** @param array<string, mixed> $condition @return array<string, mixed> */
    private static function condition(array $condition): array
    {
        $condition = self::ordered($condition, ['all', 'any', 'not', 'field', 'op', 'value', 'selection_empty', 'expr']);
        foreach (['all', 'any'] as $key) {
            if (isset($condition[$key])) {
                $condition[$key] = array_map([self::class, 'condition'], $condition[$key]);
            }
        }
        if (isset($condition['not'])) {
            $condition['not'] = self::condition((array) $condition['not']);
        }
        return $condition;
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private static function orderedAction(array $action): array
    {
        $action = self::ordered($action, ['title', 'icon', 'type', 'enabled', 'kind', 'scope', 'handler', 'presentation', 'confirm', 'show_when', 'disabled_when', 'afterSuccess', 'afterError', 'props']);
        if (isset($action['presentation'])) {
            $action['presentation'] = self::ordered((array) $action['presentation'], ['type', 'mount', 'props']);
        }
        if (isset($action['confirm'])) {
            $action['confirm'] = self::sortMap($action['confirm']);
        }
        foreach (['show_when', 'disabled_when'] as $key) {
            if (isset($action[$key])) {
                $action[$key] = self::condition((array) $action[$key]);
            }
        }
        foreach (['afterSuccess', 'afterError'] as $key) {
            if (isset($action[$key])) {
                $action[$key] = self::ordered((array) $action[$key], ['close', 'refresh', 'message', 'navigate', 'reset', 'emit']);
            }
        }
        if (isset($action['props'])) {
            $action['props'] = self::sortMap($action['props']);
        }
        return $action;
    }

    /** @param mixed $value @return mixed */
    private static function sortMap(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = self::sortMap(get_object_vars($value));
            return (object) $properties;
        }
        if (!is_array($value) || self::isList($value)) {
            return is_array($value) ? array_map([self::class, 'sortMap'], $value) : $value;
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::sortMap($value[$key]);
        }
        return $result;
    }

    /** @param mixed $value @param list<string> $keys @return array<string, mixed> */
    private static function ordered(mixed $value, array $keys): mixed
    {
        $wasObject = is_object($value);
        $value = is_array($value) ? $value : ($wasObject ? get_object_vars($value) : []);
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $result[$key] = $value[$key];
            }
        }
        return $wasObject && $result === [] ? (object) [] : $result;
    }

    /** @param mixed $value */
    private static function encodeOrdered(mixed $value): string
    {
        $value = self::normalize($value);
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return str_replace(['<', '>', '&', "\u{2028}", "\u{2029}"], ['\\u003c', '\\u003e', '\\u0026', '\\u2028', '\\u2029'], $encoded);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public static function withoutEmpty(array $value, array $required = []): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array($key, $required, true)) {
                $result[$key] = $item;
                continue;
            }
            if ($item === null || $item === '' || $item === false || $item === 0 || $item === 0.0 || $item === []) {
                continue;
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
