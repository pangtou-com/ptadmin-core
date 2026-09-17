<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

/**
 * Schema 字段类型目录，集中声明存储类型、可用校验和是否支持多值。
 */
final class FieldType
{
    public const STRING = 'string';
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const INTEGER = 'integer';
    public const BOOLEAN = 'boolean';
    public const DATE = 'date';
    public const DATETIME = 'datetime';
    public const JSON = 'json';
    /** @return array<string, array<string, mixed>> */
    public static function specs(): array
    {
        return ['string' => ['storage' => 'string', 'runtime' => 'string', 'multiple' => true, 'listable' => true, 'filterable' => true, 'sortable' => true], 'text' => ['storage' => 'text', 'runtime' => 'string', 'multiple' => false, 'listable' => false, 'filterable' => false, 'sortable' => false], 'number' => ['storage' => 'float', 'runtime' => 'float64', 'multiple' => true, 'listable' => true, 'filterable' => true, 'sortable' => true], 'integer' => ['storage' => 'integer', 'runtime' => 'int64', 'multiple' => true, 'listable' => true, 'filterable' => true, 'sortable' => true], 'boolean' => ['storage' => 'boolean', 'runtime' => 'bool', 'multiple' => false, 'listable' => true, 'filterable' => true, 'sortable' => true], 'date' => ['storage' => 'timestamp', 'runtime' => 'string', 'multiple' => false, 'listable' => true, 'filterable' => true, 'sortable' => true], 'datetime' => ['storage' => 'timestamp', 'runtime' => 'string', 'multiple' => false, 'listable' => true, 'filterable' => true, 'sortable' => true], 'json' => ['storage' => 'json', 'runtime' => 'object', 'multiple' => false, 'listable' => false, 'filterable' => false, 'sortable' => false]];
    }
    public static function supported(string $type): bool
    {
        return array_key_exists(strtolower(trim($type)), self::specs());
    }
    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_keys(self::specs());
    }
}
