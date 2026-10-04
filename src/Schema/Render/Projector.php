<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Render;

use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\ResourceVersion;

/**
 * 将元数据模型和已发布 Schema 投影为前端消费的稳定资源描述，隐藏数据库模型细节。
 */
final class Projector
{
    /**
     * 合并字段投影排序、展示能力和 Schema 定义；禁用动作不会出现在输出协议中。
     *
     * @param iterable<object> $projections
     * @return array<string, mixed>
     */
    public static function project(?Resource $resource, ?ResourceVersion $version, iterable $projections = []): array
    {
        $schema = new Schema((array) ($version?->schema_json ?? []));
        $byName = [];
        foreach ($projections as $projection) {
            $byName[$projection->name] = $projection;
        }
        $fields = [];
        foreach ((array) $schema->fields as $index => $field) {
            $projection = $byName[$field['name']] ?? null;
            $fields[] = self::projectField($field, $projection, $index);
        }
        usort($fields, static fn (array $left, array $right): int => ($left['sort'] ?? 0) <=> ($right['sort'] ?? 0));
        $actions = [];
        foreach ((array) $schema->actions as $name => $action) {
            if (!array_key_exists('enabled', $action) || $action['enabled']) {
                unset($action['enabled']);
                $action = self::withoutEmpty($action);
                $actions[$name] = $action === [] ? (object) [] : $action;
            }
        }
        $resourceKey = $resource?->resource_key ?? $schema->name;
        $resourceTitle = $schema->title ?: ($resource?->title ?? '');
        $resourceDescription = ($schema->description ?? '') ?: ($resource?->description ?? '');
        $resourceOutput = self::withoutEmpty([
            'name' => $resourceKey,
            'title' => $resourceTitle,
            'description' => $resourceDescription,
            'source_type' => $resource?->source_type ?? '',
            'owner_type' => $resource?->owner_type ?? '',
            'owner_code' => $resource?->owner_code ?? '',
        ], ['name']);
        $schemaOutput = self::withoutEmpty([
            'version' => '1.0',
            'name' => $resourceKey,
            'title' => $resourceTitle,
            'primaryKey' => 'id',
            'extensions' => $schema->extensions ?? null,
            'capabilities' => $schema->capabilities ?? [],
            'state' => $schema->state ?? null,
            'tree' => $schema->tree ?? null,
            'constraints' => $schema->constraints ?? [],
            'fields' => $fields,
            'views' => $schema->views ?? [],
            'actions' => $actions,
        ], ['version', 'name', 'fields']);

        return ['resource' => $resourceOutput, 'schema' => $schemaOutput];
    }

    /** @param array<string, mixed> $field */
    private static function projectField(array $field, ?object $projection, int $index): array
    {
        $projected = [
            'id' => $projection?->id,
            'resource_id' => $projection?->resource_id,
            'version_id' => $projection?->version_id,
            'name' => $field['name'],
            'sort' => $projection?->sort ?? $index + 1,
            'type' => $field['type'],
            'label' => $field['label'] ?? '',
            'default_expr' => $field['default_expr'] ?? null,
            'computed_expr' => $field['computed_expr'] ?? null,
            'computed_triggers' => $field['computed_triggers'] ?? [],
            'validation' => $field['validation'] ?? [],
            'column_name' => $projection?->column_name ?? (!empty($field['persisted']) ? $field['name'] : ''),
            'persisted' => $projection?->persisted ?? ($field['persisted'] ?? true),
            'multiple' => $projection?->multiple ?? ($field['multiple'] ?? false),
            'required' => $projection?->required ?? ($field['required'] ?? false),
            'readonly' => $projection?->readonly ?? ($field['readonly'] ?? false),
            'disabled' => $projection?->disabled ?? ($field['disabled'] ?? false),
            'listable' => $projection?->listable ?? ($field['listable'] ?? false),
            'formable' => $projection?->formable ?? ($field['formable'] ?? true),
            'searchable' => $projection?->searchable ?? ($field['searchable'] ?? false),
            'sortable' => $projection?->sortable ?? ($field['sortable'] ?? false),
            'reference' => $field['reference'] ?? null,
            'options' => self::projectOptions($field),
            'extensions' => $projection?->extensions ?? ($field['extensions'] ?? []),
        ];
        if (array_key_exists('default', $field) && $field['default'] !== null) {
            $projected['default'] = $field['default'];
        }

        $required = ['name', 'type', 'label'];
        if (array_key_exists('default', $projected)) {
            $required[] = 'default';
        }

        return self::withoutEmpty($projected, $required);
    }

    /** @param array<string, mixed> $field */
    private static function projectOptions(array $field): ?array
    {
        if (isset($field['options'])) {
            $options = (array) $field['options'];
            $options['mode'] = strtolower(trim((string) ($options['mode'] ?? ''))) ?: 'static';
            if ($options['mode'] === 'remote' && isset($options['remote'])) {
                $remote = (array) $options['remote'];
                $remote['target'] = strtolower(trim((string) ($remote['target'] ?? ''))) ?: 'provider';
                $options['remote'] = self::withoutEmpty($remote, ['target']);
            }

            return self::withoutEmpty($options, ['mode']);
        }
        if (strtolower((string) ($field['type'] ?? '')) !== 'boolean') {
            return null;
        }
        $display = (array) ($field['display'] ?? []);

        return [
            'mode' => 'static',
            'value_policy' => 'closed',
            'items' => [
                ['label' => (string) ($display['true_text'] ?? '是'), 'value' => true],
                ['label' => (string) ($display['false_text'] ?? '否'), 'value' => false],
            ],
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $required */
    private static function withoutEmpty(array $value, array $required = []): array
    {
        foreach ($value as $key => $item) {
            if (in_array($key, $required, true)) {
                continue;
            }
            if ($item === null || $item === '' || $item === false || $item === 0 || $item === 0.0 || $item === []) {
                unset($value[$key]);
            }
        }

        return $value;
    }
}
