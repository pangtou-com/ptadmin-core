<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\ResourceVersion;

/** 校验 Runtime 描述符与数据库中 active Schema 的 Action 声明是否一致。 */
final class RuntimeRegistryValidator
{
    /** @param list<string>|null $resourceKeys */
    public function assertValid(RuntimeRegistrySnapshot $snapshot, ?array $resourceKeys = null): void
    {
        $diagnostics = $this->validate($snapshot, $resourceKeys);
        if ($diagnostics === []) {
            return;
        }

        throw new RuntimeDiagnostic(
            'runtime_validation_failed',
            'Runtime bindings do not match the active Schema definitions.',
            ['diagnostics' => json_encode($diagnostics, JSON_UNESCAPED_SLASHES) ?: '[]'],
        );
    }

    /** @param list<string>|null $resourceKeys @return list<array<string, mixed>> */
    public function validate(RuntimeRegistrySnapshot $snapshot, ?array $resourceKeys = null): array
    {
        $descriptor = $snapshot->toArray();
        $resources = (array) ($descriptor['resources'] ?? []);
        $diagnostics = [];
        $reserved = ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge'];
        $storedResources = [];
        foreach (Resource::all() as $resource) {
            $storedResources[$resource->resource_key] = $resource;
        }
        $activeSchemas = [];
        foreach (ResourceVersion::all() as $version) {
            if ($version->status === 'active' && !isset($activeSchemas[$version->resource_id])) {
                $activeSchemas[$version->resource_id] = $version;
            }
        }
        $resourceKeys = $resourceKeys === null
            ? array_values(array_unique([...array_keys($resources), ...array_keys($storedResources)]))
            : array_values(array_unique($resourceKeys));
        sort($resourceKeys, SORT_STRING);

        foreach ($resourceKeys as $resourceKey) {
            $resourceDescriptor = $resources[$resourceKey] ?? null;
            $hasRuntimeDescriptor = is_array($resourceDescriptor);
            $resource = $storedResources[$resourceKey] ?? null;
            if ($resource === null) {
                if ($hasRuntimeDescriptor) {
                    $diagnostics[] = $this->diagnostic('resource_not_found', $resourceKey);
                }
                continue;
            }
            if ($resource->status === 'disabled') {
                if ($hasRuntimeDescriptor) {
                    $diagnostics[] = $this->diagnostic('resource_disabled', $resourceKey);
                }
                continue;
            }
            $active = $activeSchemas[$resource->id] ?? null;
            if ($active === null) {
                if ($hasRuntimeDescriptor) {
                    $diagnostics[] = $this->diagnostic('active_schema_not_found', $resourceKey);
                }
                continue;
            }
            $schema = $active->schema_json;
            $declared = is_array($schema['actions'] ?? null) ? $schema['actions'] : [];
            $registered = is_array($resourceDescriptor['actions'] ?? null) ? $resourceDescriptor['actions'] : [];
            foreach ($registered as $actionKey => $_binding) {
                if (in_array((string) $actionKey, $reserved, true)) {
                    continue;
                }
                $definition = $declared[$actionKey] ?? null;
                if (!is_array($definition) || ($definition['enabled'] ?? true) === false) {
                    $diagnostics[] = $this->diagnostic('action_not_declared', $resourceKey, (string) $actionKey);
                }
            }
            foreach ($declared as $actionKey => $definition) {
                if (in_array((string) $actionKey, $reserved, true) || !is_array($definition) || (($definition['enabled'] ?? true) === false)) {
                    continue;
                }
                if (!isset($registered[$actionKey])) {
                    $diagnostics[] = $this->diagnostic('action_binding_missing', $resourceKey, (string) $actionKey);
                }
            }
        }

        return $diagnostics;
    }

    /** @return array<string, mixed> */
    private function diagnostic(string $code, string $resourceKey, ?string $actionKey = null): array
    {
        return array_filter([
            'code' => $code,
            'resource_key' => $resourceKey,
            'action_key' => $actionKey,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
