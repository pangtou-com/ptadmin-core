<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;

final class AddonResourceSynchronizer implements AddonResourceSynchronizerInterface
{
    public function __construct(
        private ResourceServiceInterface $resources,
        private ConfigService $configs,
        private ConnectionInterface $database,
        private SchemaSourceDiscovery $schemaSources,
        private SchemaFileSynchronizer $schemaSynchronizer,
        private MenuSynchronizer $menus,
    ) {
    }

    /** @return array{schemas:int,configs:int,admin_resources:int} */
    public function sync(string $directory, string $code): array
    {
        return $this->database->transaction(function () use ($directory, $code): array {
            $counts = ['schemas' => 0, 'configs' => 0, 'admin_resources' => 0];
            $ownerCode = strtolower($code);
            $schemaKeys = [];
            $configKeys = [];
            $schemaFiles = $this->schemaSources->discoverDirectory(
                $directory.'/database/schemas',
                'addon',
                $ownerCode,
                'addon:'.$code
            );
            foreach ($this->schemaSynchronizer->sync($schemaFiles, false, false) as $result) {
                $schemaKey = $result['resource'];
                if ($result['status'] === 'blocked') {
                    throw new AddonManifestException(
                        'Addon Schema requires an explicit guarded or destructive publish.',
                        'schema_publish_blocked',
                        ['resource_key' => $schemaKey, 'risk' => $result['risk']]
                    );
                }
                $storedResource = Resource::query()->where('resource_key', $schemaKey)->first();
                if ($storedResource?->status === 'disabled') $this->resources->enableResource($schemaKey);
                $schemaKeys[] = $schemaKey;
                $counts['schemas']++;
            }
            foreach ($this->jsonFiles($directory.'/Config') as $path) {
                $definitions = $this->objectOrList($path);
                foreach ($definitions as $definition) {
                    $definition['owner_type'] = 'addon';
                    $definition['owner_code'] = $ownerCode;
                    $this->configs->syncDefinition($definition);
                    $configKeys[] = (string) ($definition['config_key'] ?? '');
                    $counts['configs']++;
                }
            }
            $menuResult = $this->menus->syncAddon($directory, $code);
            $counts['admin_resources'] = count($menuResult['resource_keys']);
            $schemaQuery = Resource::query()->where(['owner_type' => 'addon', 'owner_code' => $ownerCode]);
            if ($schemaKeys !== []) $schemaQuery->whereNotIn('resource_key', $schemaKeys);
            foreach ($schemaQuery->get() as $resource) $this->resources->disableResource((string) $resource->resource_key);

            $configQuery = ConfigDefinition::query()->where(['owner_type' => 'addon', 'owner_code' => $ownerCode]);
            if ($configKeys !== []) $configQuery->whereNotIn('config_key', $configKeys);
            $configQuery->update(['status' => false]);

            return $counts;
        });
    }

    public function deactivate(string $code): void
    {
        $ownerCode = strtolower($code);
        $this->database->transaction(function () use ($ownerCode): void {
            foreach (Resource::query()->where(['owner_type' => 'addon', 'owner_code' => $ownerCode])->get() as $resource) {
                $this->resources->disableResource((string) $resource->resource_key);
            }
            ConfigDefinition::query()->where(['owner_type' => 'addon', 'owner_code' => $ownerCode])->update(['status' => false]);
            $this->menus->deactivateAddon($ownerCode);
        });
    }

    /** @return array<int, string> */
    private function jsonFiles(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $paths = glob($directory.'/*.json') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<int, array<string, mixed>> */
    private function objectOrList(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) throw new AddonManifestException('Addon resource JSON is invalid.', 'invalid_resource_definition', ['path' => $path]);
        $items = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($items as $item) if (!is_array($item) || array_is_list($item)) throw new AddonManifestException('Addon resource definition must be an object.', 'invalid_resource_definition', ['path' => $path]);
        return $items;
    }
}
