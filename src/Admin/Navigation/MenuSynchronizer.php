<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Navigation;

use Illuminate\Database\DatabaseManager;
use PTAdmin\Addon\Catalog\AddonCatalog;
use RuntimeException;

final class MenuSynchronizer
{
    /** @var list<string> */
    private const TYPES = ['group', 'schema', 'page', 'external', 'action'];

    public function __construct(
        private DatabaseManager $database,
        private AddonCatalog $addons,
        private string $coreMenuPath,
        private string $addonsPath,
    ) {
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    public function syncCore(): array
    {
        return $this->database->transaction(fn (): array => $this->syncFile($this->coreMenuPath, 'core', 'ptadmin'));
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    public function syncAddon(string $directory, string $code): array
    {
        $normalizedCode = strtolower($code);
        $canonical = rtrim($directory, '/').'/Resources/menu.json';
        $legacy = rtrim($directory, '/').'/resources/menu.json';
        if (!is_file($canonical) && is_file($legacy)) {
            throw new RuntimeException(sprintf('插件 %s 菜单资源必须使用 Resources/menu.json：%s', $code, $legacy));
        }

        return $this->database->transaction(fn (): array => $this->syncFile(
            $canonical,
            'addon',
            $normalizedCode,
        ));
    }

    /** @return array{core:array<string,mixed>,addons:array<string,array<string,mixed>>,totals:array<string,int>} */
    public function syncAll(): array
    {
        $discovery = $this->addons->scan(false);
        if ($discovery['errors'] !== []) {
            $error = $discovery['errors'][0];
            throw new RuntimeException(sprintf(
                '菜单资源同步失败，插件 %s 在发现阶段失败：%s',
                (string) ($error['code'] ?? 'unknown'),
                (string) ($error['message'] ?? 'unknown error'),
            ));
        }

        return $this->database->transaction(function () use ($discovery): array {
            $core = $this->syncFile($this->coreMenuPath, 'core', 'ptadmin');
            $addons = [];
            $totals = $this->emptyCounts();
            $this->mergeCounts($totals, $core);

            foreach ($discovery['addons'] as $addon) {
                $directoryName = (string) $addon['code'];
                $code = strtolower($directoryName);
                $directory = rtrim($this->addonsPath, '/').'/'.$directoryName;
                if (($addon['state'] ?? null) === 'active') {
                    if (!is_file($directory.'/Resources/menu.json') && is_file($directory.'/resources/menu.json')) {
                        throw new RuntimeException(sprintf('插件 %s 菜单资源必须使用 Resources/menu.json：%s', $directoryName, $directory.'/resources/menu.json'));
                    }
                    $result = $this->syncFile($directory.'/Resources/menu.json', 'addon', $code);
                } else {
                    $result = $this->deactivateOwner('addon', $code);
                }
                $addons[$code] = $result;
                $this->mergeCounts($totals, $result);
            }

            return ['core' => $core, 'addons' => $addons, 'totals' => $totals];
        });
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    public function deactivateAddon(string $code): array
    {
        return $this->database->transaction(fn (): array => $this->deactivateOwner('addon', strtolower($code)));
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    private function syncFile(string $path, string $ownerType, string $ownerCode): array
    {
        $definitions = $this->readDefinitions($path, $ownerType, $ownerCode);
        $this->validateDefinitions($definitions, $ownerType, $ownerCode);
        $result = $this->emptyCounts();
        $resourceKeys = [];

        foreach ($definitions as $definition) {
            $key = $definition['resource_key'];
            $existing = $this->database->table('admin_resources')->where('resource_key', $key)->first();
            $this->assertOwner($existing, $key, $ownerType, $ownerCode);
            $values = $this->databaseValues($definition, $ownerType, $ownerCode);
            $resourceKeys[] = $key;

            if ($existing === null) {
                $now = now();
                $this->database->table('admin_resources')->insert($values + [
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                $result['created']++;
                continue;
            }

            $changed = false;
            foreach ($values as $column => $value) {
                if ($this->sameValue($existing->{$column} ?? null, $value)) {
                    continue;
                }
                $changed = true;
                break;
            }
            if (!$changed && $existing->deleted_at === null) {
                $result['unchanged']++;
                continue;
            }

            $values['updated_at'] = now();
            $values['deleted_at'] = null;
            $this->database->table('admin_resources')->where('id', $existing->id)->update($values);
            $result['updated']++;
        }

        $disabled = $this->deactivateOwner($ownerType, $ownerCode, $resourceKeys);
        $result['disabled'] = $disabled['disabled'];
        $result['resource_keys'] = $resourceKeys;

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function readDefinitions(string $path, string $ownerType, string $ownerCode): array
    {
        if (!is_file($path)) {
            if ($ownerType === 'addon') {
                return [];
            }
            throw new RuntimeException(sprintf('Core 菜单资源文件不存在：%s', $path));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf('菜单资源文件不是有效 JSON：%s', $path), 0, $exception);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(sprintf('菜单资源文件必须是 JSON 数组：%s', $path));
        }

        $definitions = [];
        foreach ($decoded as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new RuntimeException(sprintf('菜单资源 %s 的第 %d 项必须是对象。', $path, $index));
            }
            $definitions[] = $this->normalizeDefinition($item, $path, $ownerType, $ownerCode);
        }

        return $definitions;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeDefinition(array $item, string $path, string $ownerType, string $ownerCode): array
    {
        $key = trim((string) ($item['resource_key'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9._-]{1,190}$/', $key) !== 1) {
            throw new RuntimeException(sprintf('菜单资源 %s 的 resource_key 无效：%s', $path, $key));
        }
        $type = (string) ($item['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException(sprintf('菜单资源 %s 的类型无效：%s', $key, $type));
        }

        $rawPath = (string) ($item['path'] ?? '');

        return [
            'resource_key' => $key,
            'title' => (string) ($item['title'] ?? $key),
            'type' => $type,
            'parent_key' => trim((string) ($item['parent_key'] ?? '')),
            'path' => $this->normalizePath($rawPath, $type, $ownerType, $ownerCode, $key),
            'target_resource_key' => (string) ($item['target_resource_key'] ?? ''),
            'external_url' => (string) ($item['external_url'] ?? ''),
            'icon' => (string) ($item['icon'] ?? ''),
            'is_menu' => (bool) ($item['is_menu'] ?? false),
            'status' => (bool) ($item['status'] ?? true),
            'sort' => (int) ($item['sort'] ?? 0),
            'abilities' => $this->abilityList($item['abilities'] ?? [], $key),
            'meta' => is_array($item['meta'] ?? null) ? $this->sortKeys($item['meta']) : [],
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
        ];
    }

    /** @param list<array<string,mixed>> $definitions */
    private function validateDefinitions(array $definitions, string $ownerType, string $ownerCode): void
    {
        $byKey = [];
        foreach ($definitions as $definition) {
            $key = $definition['resource_key'];
            if (isset($byKey[$key])) {
                throw new RuntimeException(sprintf('资源声明重复：%s', $key));
            }
            $byKey[$key] = $definition;
            $type = $definition['type'];
            if ($definition['sort'] < 0) {
                throw new RuntimeException(sprintf('资源 %s 的 sort 不能小于 0。', $key));
            }
            if ($type === 'action' && $definition['is_menu']) {
                throw new RuntimeException(sprintf('Action 资源不能显示为菜单：%s', $key));
            }
            if ($type === 'group' && ($definition['target_resource_key'] !== '' || $definition['external_url'] !== '')) {
                throw new RuntimeException(sprintf('分组资源不能配置页面绑定：%s', $key));
            }
            if ($type === 'schema' && $definition['target_resource_key'] === '') {
                throw new RuntimeException(sprintf('Schema 资源必须配置 target_resource_key：%s', $key));
            }
            if ($type === 'external' && $definition['external_url'] === '') {
                throw new RuntimeException(sprintf('外链资源必须配置 external_url：%s', $key));
            }
            if ($type === 'action' && ($definition['path'] !== '' || $definition['target_resource_key'] !== '' || $definition['external_url'] !== '')) {
                throw new RuntimeException(sprintf('资源 %s 的页面字段与 type 不匹配。', $key));
            }
            if ($definition['is_menu'] && $definition['path'] === '' && !in_array($type, ['group'], true)) {
                throw new RuntimeException(sprintf('菜单资源必须配置 path：%s', $key));
            }
            if ($definition['is_menu'] && $definition['path'] !== '' && !preg_match('!^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$!', $definition['path'])) {
                throw new RuntimeException(sprintf('菜单资源 path 无效：%s', $key));
            }
            if ($type === 'external' && !filter_var($definition['external_url'], FILTER_VALIDATE_URL)) {
                throw new RuntimeException(sprintf('外链地址无效：%s', $key));
            }
            if ($type === 'external' && !in_array((string) parse_url($definition['external_url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new RuntimeException(sprintf('外链只支持 HTTP/HTTPS：%s', $key));
            }
        }

        foreach ($definitions as $definition) {
            $parentKey = $definition['parent_key'];
            if ($parentKey === '') {
                continue;
            }
            $parent = $byKey[$parentKey] ?? $this->database->table('admin_resources')->where('resource_key', $parentKey)->first();
            if ($parent === null) {
                throw new RuntimeException(sprintf('资源 %s 的父级不存在：%s', $definition['resource_key'], $parentKey));
            }
            $parentType = is_array($parent) ? $parent['type'] : (string) $parent->type;
            $parentIsMenu = is_array($parent) ? $parent['is_menu'] : (bool) $parent->is_menu;
            if ($definition['is_menu'] && (!$parentIsMenu || in_array($parentType, ['action', 'external'], true))) {
                throw new RuntimeException(sprintf('资源 %s 的父级不能作为导航目录：%s', $definition['resource_key'], $parentKey));
            }
        }

        foreach ($definitions as $definition) {
            $seen = [];
            $current = $definition['resource_key'];
            while ($current !== '') {
                if (isset($seen[$current])) {
                    throw new RuntimeException(sprintf('资源父级关系形成循环：%s', $definition['resource_key']));
                }
                $seen[$current] = true;
                $parent = $byKey[$current]['parent_key'] ?? null;
                if ($parent === null) {
                    $parent = $this->database->table('admin_resources')->where('resource_key', $current)->value('parent_key');
                }
                $current = (string) ($parent ?? '');
            }
        }

        $paths = [];
        foreach ($definitions as $definition) {
            if (!$definition['is_menu'] || $definition['path'] === '') {
                continue;
            }
            if (isset($paths[$definition['path']])) {
                throw new RuntimeException(sprintf('菜单路径重复：%s', $definition['path']));
            }
            $paths[$definition['path']] = true;
        }
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function databaseValues(array $definition, string $ownerType, string $ownerCode): array
    {
        $meta = $definition['meta'];
        $meta['owner_type'] = $ownerType;
        $meta['owner_code'] = $ownerCode;
        if ($definition['target_resource_key'] !== '') {
            $meta['target_resource_key'] = $definition['target_resource_key'];
        }
        if ($definition['icon'] !== '') {
            $meta['icon'] = $definition['icon'];
        }
        if ($definition['external_url'] !== '') {
            $meta['external_url'] = $definition['external_url'];
        }
        if ($definition['abilities'] !== []) {
            $meta['abilities'] = $definition['abilities'];
        }
        ksort($meta);

        return [
            'resource_key' => $definition['resource_key'],
            'title' => $definition['title'],
            'type' => $definition['type'],
            'parent_key' => $definition['parent_key'],
            'path' => $definition['path'],
            'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_menu' => $definition['is_menu'],
            'status' => $definition['status'],
            'sort' => $definition['sort'],
            'module' => $ownerType === 'addon' ? 'addon' : 'admin',
            'icon' => $definition['icon'] !== '' ? $definition['icon'] : null,
        ];
    }

    /** @return list<string> */
    private function abilityList(mixed $value, string $resourceKey): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('资源 %s 的 abilities 必须是字符串数组。', $resourceKey));
        }
        $abilities = [];
        foreach ($value as $ability) {
            if (!is_string($ability) || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $ability) !== 1) {
                throw new RuntimeException(sprintf('资源 %s 包含无效的权限动作。', $resourceKey));
            }
            $abilities[] = $ability;
        }

        return array_values(array_unique($abilities));
    }

    private function normalizePath(string $path, string $type, string $ownerType, string $ownerCode, string $resourceKey): string
    {
        if ($path === '') {
            return '';
        }

        if ($ownerType === 'addon' && in_array($type, ['group', 'schema', 'page'], true)) {
            if (str_starts_with($path, '/') || preg_match('!^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$!', $path) !== 1) {
                throw new RuntimeException(sprintf('插件资源 path 必须是相对路由片段：%s', $resourceKey));
            }

            return '/plugins/'.rawurlencode($ownerCode).'/'.$path;
        }

        if (preg_match('!^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$!', $path) !== 1) {
            throw new RuntimeException(sprintf('菜单资源 path 无效：%s', $resourceKey));
        }

        return $path;
    }

    /** @param object|null $existing */
    private function assertOwner(?object $existing, string $key, string $ownerType, string $ownerCode): void
    {
        if ($existing === null) {
            return;
        }
        $meta = json_decode((string) ($existing->meta_json ?? ''), true);
        $existingType = is_array($meta) ? (string) ($meta['owner_type'] ?? '') : '';
        $existingCode = is_array($meta) ? (string) ($meta['owner_code'] ?? '') : '';
        if ($existingType !== '' && ($existingType !== $ownerType || $existingCode !== $ownerCode)) {
            throw new RuntimeException(sprintf('资源编码 owner 冲突：%s', $key));
        }
    }

    /** @param list<string> $keepKeys @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    private function deactivateOwner(string $ownerType, string $ownerCode, array $keepKeys = []): array
    {
        $query = $this->database->table('admin_resources')->whereNull('deleted_at');
        $disabled = 0;
        foreach ($query->get() as $resource) {
            $meta = json_decode((string) ($resource->meta_json ?? ''), true);
            if (!is_array($meta) || ($meta['owner_type'] ?? null) !== $ownerType || ($meta['owner_code'] ?? null) !== $ownerCode) {
                continue;
            }
            if (in_array((string) $resource->resource_key, $keepKeys, true)) {
                continue;
            }
            $this->database->table('admin_resources')->where('id', $resource->id)->update([
                'status' => false,
                'updated_at' => now(),
            ]);
            $disabled++;
        }

        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => $disabled, 'resource_keys' => $keepKeys];
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    private function emptyCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => 0, 'resource_keys' => []];
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function mergeCounts(array &$target, array $source): void
    {
        foreach (['created', 'updated', 'unchanged', 'disabled'] as $key) {
            $target[$key] += (int) ($source[$key] ?? 0);
        }
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        if (is_bool($left)) {
            return $left === (bool) $right;
        }
        if (is_int($left)) {
            return $left === (int) $right;
        }

        return (string) $left === (string) $right;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }
        ksort($value);

        return $value;
    }
}
