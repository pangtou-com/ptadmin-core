<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Navigation;

use Illuminate\Database\DatabaseManager;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Admin\Auth\FounderAccess;
use RuntimeException;

/** 一个声明文件分别同步导航与业务权限；导航生命周期不传播到权限。 */
final class MenuSynchronizer
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AddonCatalog $addons,
        private readonly string $coreMenuPath,
        private readonly string $addonsPath,
        private readonly string $applicationPath = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function syncCore(): array
    {
        return $this->database->transaction(fn (): array => $this->syncFile($this->coreMenuPath, 'core', 'ptadmin'));
    }

    /** @return array<string,mixed> */
    public function syncAddon(string $directory, string $code): array
    {
        return $this->database->transaction(fn (): array => $this->syncFile(rtrim($directory, '/').'/resources/menu.json', 'addon', strtolower($code)));
    }

    /** @return array<string,mixed> */
    public function syncApp(string $directory): array
    {
        return $this->database->transaction(fn (): array => $this->syncFile(rtrim($directory, '/').'/resources/menu.json', 'app', 'host'));
    }

    /** @return array<string,mixed> */
    public function syncAll(): array
    {
        $discovery = $this->addons->scan();
        if ($discovery['errors'] !== []) {
            throw new RuntimeException('菜单同步发现插件失败：'.json_encode($discovery['errors'], JSON_UNESCAPED_UNICODE));
        }
        $sources = [['path' => $this->coreMenuPath, 'owner_type' => 'core', 'owner_code' => 'ptadmin', 'active' => true, 'label' => 'core']];
        if ($this->applicationPath !== '') {
            $sources[] = ['path' => rtrim($this->applicationPath, '/').'/resources/menu.json', 'owner_type' => 'app', 'owner_code' => 'host', 'active' => true, 'label' => 'app'];
        }
        foreach ($discovery['addons'] as $addon) {
            $sources[] = ['path' => rtrim($this->addonsPath, '/').'/'.$addon['code'].'/resources/menu.json',
                'owner_type' => 'addon', 'owner_code' => strtolower((string) $addon['code']),
                'active' => ($addon['state'] ?? null) === 'active', 'label' => 'addon:'.strtolower((string) $addon['code'])];
        }
        return $this->database->transaction(function () use ($sources): array {
            $results = $this->syncSources($sources);
            $addons = [];
            $totals = $this->emptyCounts();
            foreach ($results as $label => $result) {
                if (str_starts_with($label, 'addon:')) {
                    $addons[substr($label, 6)] = $result;
                }
                foreach (['created', 'updated', 'unchanged', 'disabled'] as $field) {
                    $totals[$field] += $result[$field];
                }
            }
            return ['core' => $results['core'], 'app' => $results['app'] ?? $this->emptyCounts(), 'addons' => $addons, 'totals' => $totals];
        });
    }

    /** @return array<string,mixed> */
    public function deactivateAddon(string $code): array
    {
        return $this->database->transaction(function () use ($code): array {
            $result = $this->emptyCounts();
            $result['disabled'] = $this->disableAddonTable('admin_menus', $code) + $this->disableAddonTable('admin_resources', $code);
            return $result;
        });
    }

    private function disableAddonTable(string $table, string $code): int
    {
        return $this->database->table($table)->where('meta_json->owner_type', 'addon')
            ->where('meta_json->owner_code', strtolower($code))->where('status', true)
            ->update(['status' => false, 'updated_at' => now()]);
    }

    /** @return array<string,mixed> */
    private function syncFile(string $path, string $ownerType, string $ownerCode): array
    {
        return $this->syncSources([['path' => $path, 'owner_type' => $ownerType, 'owner_code' => $ownerCode, 'active' => true, 'label' => 'single']])['single'];
    }

    /**
     * 先归一化所有来源，再一次性读取与写入，来源数和树深度不增加查询次数。
     * @param list<array{path:string,owner_type:string,owner_code:string,active:bool,label:string}> $sources
     * @return array<string,array<string,mixed>>
     */
    private function syncSources(array $sources): array
    {
        $existingMenus = $this->database->table('admin_menus')->get()->keyBy('resource_key')->all();
        $existingPermissions = $this->database->table('admin_resources')->get()->keyBy('resource_key')->all();
        $allMenus = [];
        $allPermissions = [];
        $prepared = [];
        $sourceByOwner = [];
        foreach ($sources as $source) {
            $path = $source['path'];
            $menus = [];
            $permissions = [];
            if ($source['active']) {
                if (!is_file($path) && $source['owner_type'] === 'core') {
                    throw new RuntimeException('菜单资源文件不存在：'.$path);
                }
                try {
                    $decoded = is_file($path) ? json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR) : [];
                } catch (\JsonException $exception) {
                    throw new RuntimeException('菜单声明不是有效 JSON：'.$path, 0, $exception);
                }
                if (!is_array($decoded) || !array_is_list($decoded)) {
                    throw new RuntimeException('菜单资源文件必须是 JSON 数组：'.$path);
                }
                $this->flatten($decoded, '', $source['owner_type'], $source['owner_code'], $menus, $permissions);
            }
            foreach ([[$menus, $existingMenus, $allMenus], [$permissions, $existingPermissions, $allPermissions]] as [$definitions, $existing, $collected]) {
                foreach ($definitions as $key => $definition) {
                    if (isset($collected[$key])) {
                        throw new RuntimeException('跨来源资源编码重复：'.$key);
                    }
                    $this->assertOwner($existing[$key] ?? null, $key, $source['owner_type'], $source['owner_code']);
                }
            }
            $allMenus += $menus;
            $allPermissions += $permissions;
            $prepared[$source['label']] = ['source' => $source, 'menus' => $menus, 'permissions' => $permissions];
            $sourceByOwner[$source['owner_type'].':'.$source['owner_code']] = $source;
        }
        $disableMenus = [];
        $disablePermissions = [];
        $disabledBySource = [];
        $combinedMenus = array_replace($existingMenus, array_map(static fn (array $row): object => (object) $row, $allMenus));
        $combinedPermissions = array_replace($existingPermissions, array_map(static fn (array $row): object => (object) $row, $allPermissions));
        foreach (['menus' => $existingMenus, 'permissions' => $existingPermissions] as $kind => $existing) {
            foreach ($existing as $key => $row) {
                $meta = json_decode((string) ($row->meta_json ?? ''), true);
                $source = $sourceByOwner[($meta['owner_type'] ?? '').':'.($meta['owner_code'] ?? '')] ?? null;
                if ($source === null || !$row->status || ($kind === 'permissions' && $source['active'])
                    || ($kind === 'menus' && isset($allMenus[$key]))) {
                    continue;
                }
                $disabledBySource[$source['label']][$kind][] = (int) $row->id;
                if ($kind === 'menus') {
                    $disableMenus[] = (int) $row->id;
                    $combinedMenus[$key] = (object) array_replace((array) $row, ['status' => false]);
                } else {
                    $disablePermissions[] = (int) $row->id;
                    $combinedPermissions[$key] = (object) array_replace((array) $row, ['status' => false]);
                }
            }
        }
        $results = [];
        foreach ($prepared as $label => $definitions) {
            $source = $definitions['source'];
            $this->validate($definitions['menus'], $definitions['permissions'], $combinedMenus, $combinedPermissions, $source['owner_type'], $source['owner_code']);
            $menuChanges = $this->changes($definitions['menus'], $existingMenus);
            $permissionChanges = $this->changes($definitions['permissions'], $existingPermissions);
            $menuCounts = $menuChanges['counts'];
            $permissionCounts = $permissionChanges['counts'];
            $menuCounts['disabled'] = count($disabledBySource[$label]['menus'] ?? []);
            $permissionCounts['disabled'] = count($disabledBySource[$label]['permissions'] ?? []);
            $result = $this->emptyCounts();
            foreach (['created', 'updated', 'unchanged', 'disabled'] as $field) {
                $result[$field] = $menuCounts[$field] + $permissionCounts[$field];
            }
            $results[$label] = array_replace($result, ['resource_keys' => array_keys($definitions['permissions']),
                'menu_keys' => array_keys($definitions['menus']), 'menus' => $menuCounts, 'permissions' => $permissionCounts]);
        }
        $this->persist('admin_menus', $allMenus, $existingMenus);
        $this->persist('admin_resources', $allPermissions, $existingPermissions);
        if ($disableMenus !== []) {
            $this->database->table('admin_menus')->whereIn('id', $disableMenus)->update(['status' => false, 'updated_at' => now()]);
        }
        if ($disablePermissions !== []) {
            $this->database->table('admin_resources')->whereIn('id', $disablePermissions)->update(['status' => false, 'updated_at' => now()]);
        }
        return $results;
    }

    /** @param list<mixed> $nodes @param array<string,array<string,mixed>> $menus @param array<string,array<string,mixed>> $permissions */
    private function flatten(array $nodes, string $parent, string $ownerType, string $ownerCode, array &$menus, array &$permissions): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof \stdClass) {
                throw new RuntimeException('菜单声明项必须是对象，父级：'.$parent);
            }
            $item = get_object_vars($node);
            if (array_key_exists('abilities', $item) || ($item['type'] ?? '') === 'action') {
                throw new RuntimeException('菜单不再支持 action 或 abilities，请使用 permissions：'.($item['resource_key'] ?? $parent));
            }
            $permissionKeys = $this->readPermissions(array_key_exists('permissions', $item) ? $item['permissions'] : [], $ownerType, $ownerCode, $permissions);
            if (!isset($item['resource_key'])) {
                if (array_keys($item) !== ['permissions']) {
                    throw new RuntimeException('独立权限容器只允许 permissions 字段。');
                }
                continue;
            }
            $key = $this->key($item['resource_key']);
            if (isset($menus[$key])) {
                throw new RuntimeException('导航声明重复：'.$key);
            }
            $type = $item['type'] ?? 'page';
            if (!in_array($type, ['group', 'schema', 'page', 'external'], true)) {
                throw new RuntimeException('导航类型无效：'.$key);
            }
            if ($parent !== '' && isset($item['parent_key']) && $item['parent_key'] !== $parent) {
                throw new RuntimeException('导航 parent_key 与 children 父级不一致：'.$key);
            }
            $meta = (array) ($item['meta'] ?? []);
            unset($meta['abilities'], $meta['scopes']);
            $meta['owner_type'] = $ownerType;
            $meta['owner_code'] = $ownerCode;
            $meta['permission_keys'] = $permissionKeys;
            foreach (['target_resource_key', 'external_url'] as $field) {
                if (isset($item[$field])) {
                    $meta[$field] = $item[$field];
                }
            }
            $path = (string) ($item['path'] ?? '');
            if ($path !== '' && in_array($ownerType, ['app', 'addon'], true) && $type !== 'external') {
                if (!preg_match('!^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$!', $path)) {
                    throw new RuntimeException('模块资源 path 必须是相对路由片段：'.$key);
                }
                $path = $ownerType === 'app' ? '/app/'.$path : '/plugins/'.rawurlencode($ownerCode).'/'.$path;
            }
            $menus[$key] = [
                'resource_key' => $key, 'title' => (string) ($item['title'] ?? $key), 'type' => $type,
                'parent_key' => $parent !== '' ? $parent : (string) ($item['parent_key'] ?? ''),
                'path' => $path, 'entry_permission' => (string) ($item['entry_permission'] ?? ''),
                'visibility' => (string) ($item['visibility'] ?? 'permission'),
                'module' => in_array($ownerType, ['app', 'addon'], true) ? $ownerType : 'admin',
                'icon' => $item['icon'] ?? null, 'meta_json' => $this->json($meta),
                'is_menu' => (bool) ($item['is_menu'] ?? true), 'status' => (bool) ($item['status'] ?? true),
                'sort' => (int) ($item['sort'] ?? 0), 'deleted_at' => null,
            ];
            $children = array_key_exists('children', $item) ? $item['children'] : [];
            if (!is_array($children) || !array_is_list($children)) {
                throw new RuntimeException('菜单 children 必须是对象数组：'.$key);
            }
            $this->flatten($children, $key, $ownerType, $ownerCode, $menus, $permissions);
        }
    }

    /** @param array<string,array<string,mixed>> $definitions @return list<string> */
    private function readPermissions(mixed $items, string $ownerType, string $ownerCode, array &$definitions): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('permissions 必须是对象数组。');
        }
        $keys = [];
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                throw new RuntimeException('permissions 条目必须是对象。');
            }
            $permission = get_object_vars($item);
            if (isset($permission['ref'])) {
                if (array_keys($permission) !== ['ref']) {
                    throw new RuntimeException('权限引用只允许 ref 字段。');
                }
                $keys[] = $this->key($permission['ref']);
                continue;
            }
            $key = $this->key($permission['resource_key'] ?? null);
            if (isset($definitions[$key])) {
                throw new RuntimeException('权限资源重复定义，请使用 ref：'.$key);
            }
            $operations = $permission['operations'] ?? null;
            if (!is_array($operations) || !array_is_list($operations) || $operations === []) {
                throw new RuntimeException('权限 operations 必须是非空对象数组：'.$key);
            }
            $abilities = [];
            $labels = [];
            foreach ($operations as $operation) {
                if (!$operation instanceof \stdClass || !is_string($operation->key ?? null)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $operation->key) || $operation->key === 'access'
                    || in_array($operation->key, $abilities, true)) {
                    throw new RuntimeException('权限操作无效、重复或使用了保留的 access：'.$key);
                }
                $abilities[] = $operation->key;
                $labels[$operation->key] = ['title' => (string) ($operation->title ?? $operation->key), 'desc' => (string) ($operation->desc ?? '')];
            }
            $scopes = $permission['scopes'] ?? ['all'];
            if (!is_array($scopes) || !array_is_list($scopes) || $scopes === []) {
                throw new RuntimeException('权限 scopes 必须是非空字符串数组：'.$key);
            }
            foreach ($scopes as $scope) {
                $this->key($scope);
            }
            $meta = [
                'owner_type' => $ownerType, 'owner_code' => $ownerCode,
                'abilities' => $abilities, 'operations' => $labels, 'scopes' => array_values(array_unique($scopes)),
                'target_resource_key' => (string) ($permission['target_resource_key'] ?? ''),
            ];
            $definitions[$key] = [
                'resource_key' => $key, 'title' => (string) ($permission['title'] ?? $key),
                'type' => 'permission', 'meta_json' => $this->json($meta),
                'status' => (bool) ($permission['status'] ?? true), 'sort' => (int) ($permission['sort'] ?? 0),
                'module' => in_array($ownerType, ['app', 'addon'], true) ? $ownerType : 'admin',
                'is_menu' => false, 'parent_key' => '', 'path' => '', 'deleted_at' => null,
            ];
            $keys[] = $key;
        }
        return array_values(array_unique($keys));
    }

    /** @param array<string,array<string,mixed>> $menus @param array<string,array<string,mixed>> $permissions @param array<string,object> $existingMenus @param array<string,object> $existingPermissions */
    private function validate(array $menus, array $permissions, array $existingMenus, array $existingPermissions, string $ownerType, string $ownerCode): void
    {
        foreach ([[$menus, $existingMenus], [$permissions, $existingPermissions]] as [$definitions, $existing]) {
            foreach ($definitions as $key => $definition) {
                $this->assertOwner($existing[$key] ?? null, $key, $ownerType, $ownerCode);
                if ($definition['sort'] < 0) {
                    throw new RuntimeException('sort 不能小于 0：'.$key);
                }
            }
        }
        $allMenus = array_replace(array_map(static fn (object $row): array => (array) $row, $existingMenus), $menus);
        $allPermissions = array_replace(array_map(static fn (object $row): array => (array) $row, $existingPermissions), $permissions);
        $paths = [];
        foreach ($allMenus as $key => $menu) {
            if ($menu['deleted_at'] === null && $menu['is_menu'] && $menu['path'] !== '') {
                if (isset($paths[$menu['path']]) && (isset($menus[$key]) || isset($menus[$paths[$menu['path']]]))) {
                    throw new RuntimeException('菜单路径重复：'.$menu['path']);
                }
                $paths[$menu['path']] = $key;
            }
        }
        foreach ($menus as $key => $menu) {
            $meta = json_decode($menu['meta_json'], true, 512, JSON_THROW_ON_ERROR);
            $type = $menu['type'];
            if ($type === 'schema' && empty($meta['target_resource_key'])) {
                throw new RuntimeException('Schema 导航必须配置 target_resource_key：'.$key);
            }
            if ($type === 'group' && (!empty($meta['target_resource_key']) || !empty($meta['external_url']))) {
                throw new RuntimeException('分组不能配置页面绑定：'.$key);
            }
            if ($type === 'external' && (!filter_var($meta['external_url'] ?? '', FILTER_VALIDATE_URL)
                || !in_array(parse_url($meta['external_url'], PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new RuntimeException('外链只支持有效 HTTP/HTTPS 地址：'.$key);
            }
            if ($type !== 'group' && ($menu['path'] === '' || !preg_match('!^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$!', $menu['path']))) {
                throw new RuntimeException('页面必须配置有效 path：'.$key);
            }
            if (!in_array($menu['visibility'], ['permission', 'authenticated'], true)
                || ($menu['visibility'] === 'authenticated' && $menu['entry_permission'] !== '')) {
                throw new RuntimeException('导航 visibility 与入口权限冲突：'.$key);
            }
            foreach ($meta['permission_keys'] as $permissionKey) {
                $resource = $allPermissions[$permissionKey] ?? null;
                if ($resource === null || $resource['deleted_at'] !== null) {
                    throw new RuntimeException('导航引用不存在的权限资源：'.$key.' → '.$permissionKey);
                }
            }
            if ($type !== 'group' && $menu['visibility'] === 'permission') {
                $entry = $menu['entry_permission'];
                $position = strrpos($entry, '.');
                $resourceKey = $position === false ? '' : substr($entry, 0, $position);
                $ability = $position === false ? '' : substr($entry, $position + 1);
                $resource = $allPermissions[$resourceKey] ?? null;
                $capabilities = $resource === null ? [] : json_decode($resource['meta_json'], true, 512, JSON_THROW_ON_ERROR);
                if ($resource === null || $resource['deleted_at'] !== null || !$resource['status']
                    || !in_array($ability, $capabilities['abilities'] ?? [], true)) {
                    throw new RuntimeException('导航入口权限未声明或已停用：'.$key.' → '.$entry);
                }
                if (!in_array($resourceKey, $meta['permission_keys'], true)) {
                    throw new RuntimeException('入口权限必须包含在本页面 permissions 定义或引用中：'.$key);
                }
            }
            $seen = [$key => true];
            $parentKey = $menu['parent_key'];
            while ($parentKey !== '') {
                if (isset($seen[$parentKey])) {
                    throw new RuntimeException('导航父级形成循环：'.$key);
                }
                $seen[$parentKey] = true;
                $parent = $allMenus[$parentKey] ?? null;
                if ($parent === null || $parent['deleted_at'] !== null || $parent['type'] === 'external'
                    || ($menu['is_menu'] && !$parent['is_menu'])) {
                    throw new RuntimeException('导航父级不存在或不能作为目录：'.$key.' → '.$parentKey);
                }
                $parentKey = $parent['parent_key'];
            }
        }
    }

    /** @param array<string,array<string,mixed>> $definitions @param array<string,object> $existing @return array<string,mixed> */
    private function persist(string $table, array $definitions, array $existing): array
    {
        $changes = $this->changes($definitions, $existing);
        $rows = $changes['rows'];
        if ($rows !== []) {
            $this->database->table($table)->upsert($rows, ['resource_key'], array_values(array_diff(array_keys($rows[0]), ['resource_key', 'created_at'])));
        }
        return $changes['counts'];
    }

    /** @param array<string,array<string,mixed>> $definitions @param array<string,object> $existing @return array{counts:array<string,mixed>,rows:list<array<string,mixed>>} */
    private function changes(array $definitions, array $existing): array
    {
        $counts = $this->emptyCounts();
        $rows = [];
        foreach ($definitions as $key => $values) {
            $current = $existing[$key] ?? null;
            $changed = $current === null;
            foreach ($values as $column => $value) {
                if ($current !== null && (is_bool($value) ? (bool) $current->{$column} !== $value : (string) $current->{$column} !== (string) $value)) {
                    $changed = true;
                }
            }
            $counts[$current === null ? 'created' : ($changed ? 'updated' : 'unchanged')]++;
            if ($changed) {
                $rows[] = $values + ['created_at' => $current->created_at ?? now(), 'updated_at' => now()];
            }
        }
        return ['counts' => $counts, 'rows' => $rows];
    }

    private function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9._-]{0,190}$/', $value) || in_array($value, FounderAccess::RESOURCE_KEYS, true)) {
            throw new RuntimeException('无效或保留的资源编码：'.(is_scalar($value) ? (string) $value : '(缺失)'));
        }
        return $value;
    }

    private function assertOwner(?object $existing, string $key, string $ownerType, string $ownerCode): void
    {
        if ($existing === null) {
            return;
        }
        $meta = json_decode((string) $existing->meta_json, true);
        if (($meta['owner_type'] ?? '') !== $ownerType || ($meta['owner_code'] ?? '') !== $ownerCode) {
            throw new RuntimeException('资源编码 owner 冲突：'.$key);
        }
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{created:int,updated:int,unchanged:int,disabled:int,resource_keys:list<string>} */
    private function emptyCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => 0, 'resource_keys' => []];
    }
}
