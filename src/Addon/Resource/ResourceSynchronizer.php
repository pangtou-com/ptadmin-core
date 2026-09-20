<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Resource;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;

/**
 * 在一个数据库事务内同步插件声明的 Schema、配置定义和后台资源。
 *
 * 插件升级后不再声明的资源会被停用而不是删除，保证已有配置值和业务表数据可在重新启用或回滚后继续使用。
 */
final class ResourceSynchronizer implements ResourceSynchronizerInterface
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

    /**
     * 以插件 code 作为稳定 Owner 同步全部资源，并拒绝需要显式危险发布确认的 Schema 变更。
     *
     * @return array{schemas:int,configs:int,admin_resources:int}
     */
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
                    throw new AddonException(
                        'Addon Schema requires an explicit guarded or destructive publish.',
                        'schema_publish_blocked',
                        ['resource_key' => $schemaKey, 'risk' => $result['risk']]
                    );
                }
                $storedResource = Resource::query()->where('resource_key', $schemaKey)->first();
                if ($storedResource?->status === 'disabled') {
                    $this->resources->enableResource($schemaKey);
                }
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
            if ($schemaKeys !== []) {
                $schemaQuery->whereNotIn('resource_key', $schemaKeys);
            }
            foreach ($schemaQuery->get() as $resource) {
                $this->resources->disableResource((string) $resource->resource_key);
            }

            $configQuery = ConfigDefinition::query()->where(['owner_type' => 'addon', 'owner_code' => $ownerCode]);
            if ($configKeys !== []) {
                $configQuery->whereNotIn('config_key', $configKeys);
            }
            $configQuery->update(['status' => false]);

            return $counts;
        });
    }

    /** 停用插件的资源定义和菜单可见性，不删除配置值、Schema 版本或业务数据。 */
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
        if (!is_dir($directory)) {
            return [];
        }
        $paths = glob($directory.'/*.json') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<int, array<string, mixed>> */
    private function objectOrList(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new AddonException('Addon resource JSON is invalid.', 'invalid_resource_definition', ['path' => $path]);
        }
        $items = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new AddonException('Addon resource definition must be an object.', 'invalid_resource_definition', ['path' => $path]);
            }
        }
        return $items;
    }
}
