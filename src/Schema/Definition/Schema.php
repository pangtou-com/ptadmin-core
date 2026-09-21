<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

use PTAdmin\Schema\Support\DataObject;
use PTAdmin\Schema\Support\Protocol;

/**
 * Schema 协议定义对象，负责字段/动作归一化、空值裁剪和跨语言 checksum。
 */
final class Schema extends DataObject
{
    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        parent::__construct(self::normalize($attributes));
    }

    /** @param array<string, mixed> $attributes */
    public static function normalize(array $attributes): array
    {
        $schema = $attributes;
        $schema['title'] = (string) ($schema['title'] ?? '');
        $schema['name'] = (string) ($schema['name'] ?? '');
        // Go 对空 map 应用 omitempty；这里不能沿用 Loader 为 any 值保留的空对象类型。
        foreach (['features', 'actions'] as $mapKey) {
            if (isset($schema[$mapKey]) && is_object($schema[$mapKey])) {
                $schema[$mapKey] = get_object_vars($schema[$mapKey]);
            }
        }
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            $schema['fields'] = array_map(static fn ($field): array => Field::normalize((array) $field), $schema['fields']);
        }
        if (isset($schema['actions']) && is_array($schema['actions'])) {
            foreach ($schema['actions'] as $key => $action) {
                $schema['actions'][$key] = Action::normalize((array) $action);
            }
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $schema = parent::toArray();
        // fields 是 Schema 协议的稳定数组，即使为空也必须保留，避免消费者把空定义误判为缺失字段。
        $schema = Protocol::withoutEmpty($schema, ['title', 'name', 'capabilities', 'constraints', 'views', 'fields']);
        if (!array_key_exists('capabilities', $schema)) {
            $schema['capabilities'] = (object) [];
        }
        if (!array_key_exists('constraints', $schema)) {
            $schema['constraints'] = (object) [];
        }
        if (!array_key_exists('views', $schema)) {
            $schema['views'] = (object) [];
        }
        if (isset($schema['capabilities']) && is_array($schema['capabilities'])) {
            $schema['capabilities'] = Protocol::withoutEmpty((array) $schema['capabilities']);
        }
        if (isset($schema['state']) && is_array($schema['state'])) {
            $schema['state'] = Protocol::withoutEmpty((array) $schema['state']);
        }
        if (isset($schema['tree']) && is_array($schema['tree'])) {
            $schema['tree'] = Protocol::withoutEmpty((array) $schema['tree']);
        }
        if (isset($schema['constraints']) && is_array($schema['constraints'])) {
            $schema['constraints'] = Protocol::withoutEmpty((array) $schema['constraints']);
        }
        if (isset($schema['views']) && is_array($schema['views'])) {
            $schema['views'] = Protocol::withoutEmpty((array) $schema['views'], [
                'page',
                'pages',
                'search',
                'searches',
                'table',
                'tables',
                'form',
                'forms',
                'detail',
                'details',
            ]);
        }
        if (isset($schema['fields'])) {
            $schema['fields'] = array_map(static fn (array $field): array => Field::serializeDefinition($field), $schema['fields']);
        }
        return $schema;
    }

    public function checksum(): string
    {
        return Protocol::schemaChecksum($this->toArray());
    }

    /** @return list<array{code:string,path:string,message:string}> */
    public function validate(): array
    {
        return (new Validator())->validate($this->toArray());
    }

    public static function fromArray(array $attributes): static
    {
        return new static($attributes);
    }
}
