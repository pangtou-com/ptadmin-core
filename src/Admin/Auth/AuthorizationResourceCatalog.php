<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;

final class AuthorizationResourceCatalog
{
    /** @var array<string, array{abilities:list<string>,scopes:list<string>}> */
    private array $schemaCapabilities = [];

    public function __construct(private ConnectionInterface $database)
    {
    }

    /** @param array<string, mixed> $resource @return array{abilities:list<string>,scopes:list<string>} */
    public function capabilities(array $resource): array
    {
        $meta = $this->decode($resource['meta_json'] ?? null);
        $declared = $this->stringList($meta['abilities'] ?? []);
        $type = (string) ($resource['type'] ?? '');
        if ($type === 'group') {
            return ['abilities' => [], 'scopes' => []];
        }
        if ($type !== 'schema') {
            return ['abilities' => $declared !== [] ? $declared : ['view'], 'scopes' => []];
        }

        $target = (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '');
        if ($target === '') {
            return ['abilities' => $declared, 'scopes' => []];
        }
        if (!isset($this->schemaCapabilities[$target])) {
            $this->schemaCapabilities[$target] = $this->schemaCapabilities($target);
        }
        $capabilities = $this->schemaCapabilities[$target];
        if ($declared !== []) {
            $capabilities['abilities'] = array_values(array_unique(array_merge($capabilities['abilities'], $declared)));
        }

        return $capabilities;
    }

    /** @return array{abilities:list<string>,scopes:list<string>} */
    private function schemaCapabilities(string $resourceKey): array
    {
        $resource = $this->database->table('schema_resources')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('resource_key', $resourceKey)
            ->first(['active_version_id']);
        if (!$resource || (int) $resource->active_version_id < 1) {
            return ['abilities' => [], 'scopes' => []];
        }
        $encoded = $this->database->table('schema_resource_versions')
            ->where('id', (int) $resource->active_version_id)
            ->value('schema_json');
        $schema = $this->decode($encoded);
        if ($schema === []) {
            return ['abilities' => [], 'scopes' => []];
        }

        $abilities = ['list', 'view', 'create', 'update', 'delete'];
        $capabilities = (array) ($schema['capabilities'] ?? []);
        if (!empty($capabilities['allow_recycle']) || !empty($capabilities['soft_delete'])) {
            $abilities[] = 'restore';
            $abilities[] = 'purge';
        }
        foreach ((array) ($schema['actions'] ?? []) as $action => $definition) {
            if (!is_array($definition) || !array_key_exists('enabled', $definition) || $definition['enabled']) {
                $abilities[] = (string) $action;
            }
        }

        return [
            'abilities' => array_values(array_unique($abilities)),
            'scopes' => $this->hasOwnerField($schema) ? ['all', 'self'] : ['all'],
        ];
    }

    /** @param array<string, mixed> $schema */
    private function hasOwnerField(array $schema): bool
    {
        $authorization = (array) (((array) ($schema['extensions'] ?? []))['authorization'] ?? []);
        $ownerField = (string) ($authorization['owner_field'] ?? '');
        if ($ownerField === '') {
            return false;
        }
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if (is_array($field) && ($field['name'] ?? null) === $ownerField && !empty($field['persisted'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
