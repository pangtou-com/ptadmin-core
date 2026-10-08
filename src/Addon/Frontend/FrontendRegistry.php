<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use Closure;
use JsonException;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Manifest\TrustPolicy;
use RuntimeException;

/**
 * 为当前管理员解析可加载的插件前端入口。
 *
 * Registry 同时校验插件 active 状态、插件菜单引用、Host API、sandbox、发布入口和 trusted 策略，
 * 最终只向 Session 暴露满足全部条件的最小前端描述，不返回磁盘路径或原始 manifest。
 */
final class FrontendRegistry
{
    /** @var array<string, array<string, array<string, mixed>>> 资源同步期间的已验证声明，操作结束即恢复。 */
    private array $synchronizingFlows = [];

    /**
     * @param array<int, string> $supportedHostApis 当前后台宿主可以提供的 Host API 版本
     * @param array<int, string> $supportedRuntimeProfiles trusted 模块允许使用的 Runtime Profile
     * @param array<int, string> $supportedCapabilities 宿主明确开放给插件前端的能力名称
     */
    public function __construct(
        private AddonCatalog $discovery,
        private ManifestVerifier $verifier,
        private TrustPolicy $trustPolicy,
        private LifecycleStore $lifecycle,
        private FrontendDevelopmentStore $development,
        private string $addonsPath,
        private string $publicRoot,
        private string $webPrefix,
        private string $apiPrefix,
        private array $supportedHostApis,
        private array $supportedRuntimeProfiles,
        private array $supportedCapabilities,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->publicRoot = rtrim($publicRoot, '/');
        $this->apiPrefix = trim($apiPrefix, '/');
    }

    /** 资源和预设通过同一可信声明恢复流程，不依赖菜单可见性。 */
    public function designFlow(string $reference): ?array
    {
        if (!str_contains($reference, ':')) {
            return null;
        }
        [$plugin, $id] = explode(':', $reference, 2);
        if (array_key_exists($plugin, $this->synchronizingFlows)) {
            return $this->synchronizingFlows[$plugin][$id] ?? null;
        }
        foreach ($this->discovery->scan()['addons'] as $addon) {
            if (strtolower((string) ($addon['manifest']['code'] ?? $addon['code'])) !== $plugin || $addon['state'] !== 'active') {
                continue;
            }
            $descriptor = $this->descriptor($this->addonsPath.'/'.$addon['code']);
            if ($descriptor === null) {
                return null;
            }
            try {
                $flows = $this->designFlows($addon['code'], $this->declaredDesignFlows($addon['manifest'] ?? [], $descriptor), $descriptor['entries'] ?? []);
            } catch (AddonException) {
                return null;
            }
            foreach ($flows as $flow) {
                if ($flow['id'] === $id) {
                    return $flow;
                }
            }
        }
        return null;
    }

    /**
     * 只在资源同步内读取候选声明，不以临时启用插件绕过停用状态；支持嵌套与异常恢复。
     *
     * @internal directory 和 manifest 必须来自已验证的插件同步入口。
     * @param array<string, mixed> $manifest
     */
    public function withAddonDesignFlows(string $directory, array $manifest, Closure $synchronize): mixed
    {
        $code = $manifest['code'];
        $descriptor = $this->descriptor($directory) ?? [];
        $flows = $this->designFlows($code, $this->declaredDesignFlows($manifest, $descriptor), $descriptor['entries'] ?? []);
        $previous = $this->synchronizingFlows;
        $this->synchronizingFlows[$code] = array_column($flows, null, 'id');
        try {
            return $synchronize();
        } finally {
            $this->synchronizingFlows = $previous;
        }
    }

    /**
     * 根据当前管理员权限和可见菜单生成插件模块列表；单个插件声明无效时只记录该插件错误。
     *
     * @param array<int, array<string, mixed>> $menus
     * @return array{plugins:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
     */
    public function resolve(array $menus): array
    {
        $result = $this->discovery->scan();
        $plugins = [];
        $errors = $result['errors'];
        $referencedAddonCodes = $this->addonCodes($menus);
        $registeredKeys = [];

        foreach ($result['addons'] as $addon) {
            if (($addon['state'] ?? null) !== 'active') {
                continue;
            }
            $directoryCode = (string) ($addon['code'] ?? '');
            $manifest = is_array($addon['manifest'] ?? null) ? $addon['manifest'] : [];
            $pluginCode = strtolower((string) ($manifest['code'] ?? $directoryCode));
            try {
                $frontend = $this->resolveAddonFrontend(
                    $directoryCode,
                    $manifest,
                    isset($referencedAddonCodes[$pluginCode]),
                    $registeredKeys,
                );
                $entries = $frontend['entries'];
                if ($entries === []) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $registeredKeys[] = $entry['key'];
                }
                $plugin = [
                    'owner_type' => 'addon',
                    'owner_code' => $pluginCode,
                    'code' => $pluginCode,
                    'version' => (string) ($addon['version'] ?? ''),
                    'enabled' => true,
                    'api_base' => '/'.$this->apiPrefix.'/'.rawurlencode($pluginCode),
                    'entries' => $entries,
                ];
                if ($frontend['design_flows'] !== []) {
                    $plugin['design_flows'] = $frontend['design_flows'];
                }
                $plugins[] = $plugin;
            } catch (AddonException $exception) {
                $errors[] = [
                    'code' => $pluginCode,
                    'error_code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return ['plugins' => $plugins, 'errors' => $errors];
    }

    /**
     * 解析一个插件的 frontend.json，并确保入口身份、权限和发布产物均属于该插件。
     *
     * @param array<string, mixed> $manifest
     * @param bool $referencedByMenu
     * @param array<int, string> $registeredKeys
     * @return array{entries:array<int,array<string,mixed>>,design_flows:array<int,array<string,mixed>>}
     */
    private function resolveAddonFrontend(
        string $code,
        array $manifest,
        bool $referencedByMenu,
        array $registeredKeys,
    ): array {
        if (!$referencedByMenu) {
            return ['entries' => [], 'design_flows' => []];
        }
        $descriptor = $this->descriptor($this->addonsPath.'/'.$code);
        if (null === $descriptor) {
            return ['entries' => [], 'design_flows' => []];
        }
        $hostApi = (string) ($descriptor['host_api'] ?? '');
        if (!in_array($hostApi, $this->supportedHostApis, true)) {
            throw new AddonException('The addon frontend Host API is unsupported.', 'frontend_host_api_unsupported');
        }
        $runtimeProfile = $descriptor['runtime_profile'] ?? null;
        if (null !== $runtimeProfile && !is_string($runtimeProfile)) {
            throw new AddonException('The addon frontend Runtime Profile is invalid.', 'frontend_runtime_profile_invalid');
        }
        $declaredEntries = $descriptor['entries'] ?? null;
        if (!is_array($declaredEntries) || !array_is_list($declaredEntries)) {
            throw new AddonException('The addon frontend entries must be a list.', 'frontend_entries_invalid');
        }

        $resolved = [];
        try {
            $development = $this->development->resolveInline($code, $descriptor['development'] ?? null);
        } catch (RuntimeException $exception) {
            throw new AddonException(
                $exception->getMessage(),
                'frontend_development_invalid',
                ['code' => $code]
            );
        }
        $localKeys = [];
        foreach ($declaredEntries as $declaredEntry) {
            if (!is_array($declaredEntry) || array_is_list($declaredEntry)) {
                throw new AddonException('The addon frontend entry must be an object.', 'frontend_entry_invalid');
            }
            $key = (string) ($declaredEntry['key'] ?? '');
            $title = (string) ($declaredEntry['title'] ?? '');
            if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,190}$/', $key) !== 1 || $title === '') {
                throw new AddonException('The addon frontend entry identity is invalid.', 'frontend_entry_invalid');
            }
            if (!str_starts_with(strtolower($key), strtolower($code).'.')) {
                throw new AddonException(
                    'The addon frontend entry key must use its addon namespace.',
                    'frontend_entry_namespace_invalid',
                    ['key' => $key, 'code' => $code]
                );
            }
            if (in_array($key, $localKeys, true) || in_array($key, $registeredKeys, true)) {
                throw new AddonException('The addon frontend entry key is duplicated.', 'frontend_entry_key_conflict', ['key' => $key]);
            }
            $localKeys[] = $key;

            $sandbox = (string) ($declaredEntry['sandbox'] ?? '');
            if (!in_array($sandbox, ['trusted', 'iframe'], true)) {
                throw new AddonException('The addon frontend sandbox is invalid.', 'frontend_sandbox_invalid');
            }
            $entryPath = (string) ($declaredEntry['entry'] ?? '');
            $this->assertEntryPath($entryPath, $sandbox);
            if (!is_array($development)) {
                $publishedPath = $this->publicRoot.'/'.$this->webPrefix.'/plugins/'.$code.'/'.$entryPath;
                $realPublishedPath = realpath($publishedPath);
                $pluginRoot = realpath($this->publicRoot.'/'.$this->webPrefix.'/plugins/'.$code);
                if (!is_string($realPublishedPath) || !is_string($pluginRoot)
                    || !str_starts_with($realPublishedPath, $pluginRoot.DIRECTORY_SEPARATOR)
                    || !is_file($realPublishedPath)) {
                    throw new AddonException('The addon frontend entry is not published.', 'frontend_entry_unavailable', ['entry' => $entryPath]);
                }
            }
            $capabilities = $this->stringList($declaredEntry['capabilities'] ?? [], 'frontend_capabilities_invalid');
            foreach ($capabilities as $capability) {
                if (!in_array($capability, $this->supportedCapabilities, true)) {
                    throw new AddonException('The addon frontend capability is unsupported.', 'frontend_capability_unsupported', ['capability' => $capability]);
                }
            }

            $entry = [
                'key' => $key,
                'title' => $title,
                'entry' => './plugins/'.rawurlencode($code).'/'.$entryPath.'?v='.rawurlencode((string) ($manifest['version'] ?? 'dev')),
                'sandbox' => $sandbox,
                'host_api' => $hostApi,
            ];
            if ($capabilities !== []) {
                $entry['capabilities'] = $capabilities;
            }
            if (is_array($development)) {
                if (($development['sandbox'] ?? null) !== $sandbox) {
                    throw new AddonException('The addon frontend development sandbox does not match production.', 'frontend_development_sandbox_mismatch');
                }
                $entry['development'] = [
                    'load_mode' => 'development',
                    'entry_url' => $development['entry_url'],
                    'allowed_origin' => $development['allowed_origin'],
                    'dev_revision' => $development['dev_revision'],
                    'hmr' => (bool) ($development['hmr'] ?? false),
                    'source_maps' => (bool) ($development['source_maps'] ?? false),
                    'status' => $development['status'] ?? 'expired',
                ];
            }
            if ($sandbox === 'trusted') {
                if (!is_array($development)) {
                    $entry['styles'] = (new PublishedBuildStyles())->resolve(
                        $this->publicRoot.'/'.$this->webPrefix.'/plugins/'.$code,
                        $entryPath,
                        './plugins/'.rawurlencode($code),
                    );
                }
                // trusted 模块与宿主同一执行上下文，必须同时满足包声明、Runtime Profile 和部署信任策略。
                if (($manifest['sandbox'] ?? null) !== 'trusted') {
                    throw new AddonException('The addon package does not permit trusted frontend entries.', 'frontend_trust_not_declared');
                }
                if (!is_string($runtimeProfile) || !in_array($runtimeProfile, $this->supportedRuntimeProfiles, true)) {
                    throw new AddonException('The addon frontend Runtime Profile is unsupported.', 'frontend_runtime_profile_unsupported');
                }
                $this->trustPolicy->assertTrusted($manifest);
                $entry['runtime_profile'] = $runtimeProfile;
                $entry['trust'] = $this->trust($code, $manifest);
            }
            $resolved[] = $entry;
        }

        return [
            'entries' => $resolved,
            'design_flows' => $resolved === []
                ? []
                : $this->designFlows($code, $this->declaredDesignFlows($manifest, $descriptor), $resolved),
        ];
    }

    /** 设计流程属于插件能力声明，优先从后端 manifest 读取；保留旧前端清单兼容。 */
    private function declaredDesignFlows(array $manifest, array $descriptor): mixed
    {
        return array_key_exists('design_flows', $manifest)
            ? $manifest['design_flows']
            : ($descriptor['design_flows'] ?? []);
    }

    /**
     * @param mixed $declared
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function designFlows(string $code, mixed $declared, array $entries): array
    {
        if (!is_array($declared) || !array_is_list($declared)) {
            throw new AddonException('The addon frontend design flows must be a list.', 'frontend_design_flows_invalid');
        }
        $entryKeys = [];
        $hasTrustedEntry = false;
        foreach ($entries as $entry) {
            if (is_string($entry['key'] ?? null)) {
                $entryKeys[] = $entry['key'];
            }
            $hasTrustedEntry = $hasTrustedEntry || ($entry['sandbox'] ?? null) === 'trusted';
        }
        $flowIds = [];
        $resolved = [];
        foreach ($declared as $flow) {
            if (!is_array($flow) || array_is_list($flow)) {
                throw new AddonException('The addon frontend design flow must be an object.', 'frontend_design_flows_invalid');
            }
            $id = (string) ($flow['id'] ?? '');
            $target = (string) ($flow['target'] ?? '');
            $permission = (string) ($flow['permission'] ?? '');
            if (!$this->validIdentifier($id) || in_array($id, $flowIds, true)
                || $target !== 'schema.designer' || !$this->validIdentifier($permission)) {
                throw new AddonException('The addon frontend design flow identity is invalid.', 'frontend_design_flows_invalid');
            }
            $extensionNamespace = $flow['extension_namespace'] ?? null;
            if ($extensionNamespace !== null && (!is_string($extensionNamespace) || !preg_match('/^[a-z][a-z0-9_]*$/', $extensionNamespace))) {
                throw new AddonException('The design flow extension namespace is invalid.', 'frontend_design_flows_invalid');
            }
            $namespace = $flow['namespace'] ?? null;
            if (null !== $namespace && (!is_string($namespace) || !$this->validIdentifier($namespace))) {
                throw new AddonException('The addon frontend design flow namespace is invalid.', 'frontend_design_flows_invalid');
            }
            $steps = $flow['steps'] ?? null;
            if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
                throw new AddonException('The addon frontend design flow steps must be a non-empty list.', 'frontend_design_flows_invalid');
            }
            $stepIds = [];
            $resolvedSteps = [];
            $claimedPaths = [];
            foreach ($steps as $step) {
                if (!is_array($step) || array_is_list($step)) {
                    throw new AddonException('The addon frontend design flow step must be an object.', 'frontend_design_flows_invalid');
                }
                $stepId = (string) ($step['id'] ?? '');
                $title = (string) ($step['title'] ?? '');
                if (!$this->validIdentifier($stepId) || $title === '' || in_array($stepId, $stepIds, true)) {
                    throw new AddonException('The addon frontend design flow step identity is invalid.', 'frontend_design_flows_invalid');
                }
                $mount = $this->designFlowMount($code, $step['mount'] ?? null, $entryKeys, $hasTrustedEntry);
                $stepIds[] = $stepId;
                $resolvedStep = ['id' => $stepId, 'title' => $title, 'mount' => $mount];
                $paths = $step['extension_paths'] ?? [];
                if (!is_array($paths) || !array_is_list($paths) || ($paths !== [] && $extensionNamespace === null)) {
                    throw new AddonException('The design step extension paths are invalid.', 'frontend_design_flows_invalid');
                }
                foreach ($paths as $path) {
                    if (!is_string($path) || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $path)) {
                        throw new AddonException('The design step extension path is invalid.', 'frontend_design_flows_invalid');
                    }
                    foreach ($claimedPaths as $claimed) {
                        if ($path === $claimed || str_starts_with($path, $claimed.'.') || str_starts_with($claimed, $path.'.')) {
                            throw new AddonException('The design step extension paths overlap.', 'frontend_design_flows_invalid');
                        }
                    }
                    $claimedPaths[] = $path;
                }
                if ($paths !== []) {
                    $resolvedStep['extension_paths'] = $paths;
                }
                $resolvedSteps[] = $resolvedStep;
            }
            $flowIds[] = $id;
            $resolvedFlow = [
                'id' => $id,
                'target' => $target,
                'permission' => $permission,
                'steps' => $resolvedSteps,
            ];
            if ($extensionNamespace !== null) {
                $resolvedFlow['extension_namespace'] = $extensionNamespace;
            }
            if (is_string($namespace)) {
                $resolvedFlow['namespace'] = $namespace;
            }
            $resolved[] = $resolvedFlow;
        }

        return $resolved;
    }

    /**
     * @param mixed $mount
     * @param array<int, string> $entryKeys
     * @return array<string, array<string, string>>
     */
    private function designFlowMount(string $code, mixed $mount, array $entryKeys, bool $hasTrustedEntry): array
    {
        if (!is_array($mount) || array_is_list($mount) || count($mount) !== 1) {
            throw new AddonException('The addon frontend design flow mount is invalid.', 'frontend_design_flows_invalid');
        }
        if (isset($mount['host_module']) && is_array($mount['host_module'])) {
            $reference = $mount['host_module']['ref'] ?? null;
            if (!is_string($reference) || !in_array($reference, ['schema.basic', 'schema.designer'], true)) {
                throw new AddonException('The addon frontend host module is unsupported.', 'frontend_design_flows_invalid');
            }
            return ['host_module' => ['ref' => $reference]];
        }
        if (isset($mount['frontend_contribution']) && is_array($mount['frontend_contribution'])) {
            $contribution = $mount['frontend_contribution']['contribution'] ?? null;
            if (!$hasTrustedEntry || !is_string($contribution) || !$this->validIdentifier($contribution)
                || !str_starts_with(strtolower($contribution), strtolower($code).'.')) {
                throw new AddonException('The addon frontend contribution is invalid.', 'frontend_design_flows_invalid');
            }
            return ['frontend_contribution' => ['contribution' => $contribution]];
        }
        if (isset($mount['surface']) && is_array($mount['surface'])) {
            $entry = $mount['surface']['entry'] ?? null;
            $target = $mount['surface']['target'] ?? null;
            if (!is_string($entry) || !in_array($entry, $entryKeys, true)
                || (null !== $target && (!is_string($target) || !$this->validIdentifier($target)))) {
                throw new AddonException('The addon frontend design flow surface is invalid.', 'frontend_design_flows_invalid');
            }
            $surface = ['entry' => $entry];
            if (is_string($target)) {
                $surface['target'] = $target;
            }
            return ['surface' => $surface];
        }

        throw new AddonException('The addon frontend design flow mount kind is unsupported.', 'frontend_design_flows_invalid');
    }

    private function validIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,190}$/', $value) === 1;
    }

    /** @return array<string, mixed>|null */
    private function descriptor(string $directory): ?array
    {
        $path = $directory.'/frontend/frontend.json';
        if (!is_file($path)) {
            return null;
        }
        try {
            $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AddonException('The addon frontend descriptor is invalid JSON.', 'frontend_descriptor_invalid');
        }
        if (!is_array($descriptor) || array_is_list($descriptor)) {
            throw new AddonException('The addon frontend descriptor must be an object.', 'frontend_descriptor_invalid');
        }

        return $descriptor;
    }

    private function assertEntryPath(string $path, string $sandbox): void
    {
        $segments = explode('/', $path);
        if ($path === '' || strlen($path) > 512 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $path) !== 1
            || str_starts_with($path, '/') || str_contains($path, '\\')
            || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new AddonException('The addon frontend entry path is invalid.', 'frontend_entry_path_invalid');
        }
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (($sandbox === 'trusted' && !in_array($extension, ['js', 'mjs'], true))
            || ($sandbox === 'iframe' && $extension !== 'html')) {
            throw new AddonException('The addon frontend entry type does not match its sandbox.', 'frontend_entry_type_invalid');
        }
    }

    /** @param mixed $value @return array<int, string> */
    private function stringList(mixed $value, string $errorCode): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new AddonException('The addon frontend declaration must be a string list.', $errorCode);
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,190}$/', $item) !== 1) {
                throw new AddonException('The addon frontend declaration contains an invalid value.', $errorCode);
            }
            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }

    /** @param array<int, array<string, mixed>> $menus @return array<string, bool> */
    private function addonCodes(array $menus): array
    {
        $codes = [];
        foreach ($menus as $menu) {
            $ownerType = (string) ($menu['owner_type'] ?? '');
            $code = $menu['owner_code'] ?? null;
            if ($ownerType === 'addon' && is_string($code) && $code !== '') {
                $codes[strtolower($code)] = true;
            }
            $children = $menu['children'] ?? null;
            if (is_array($children)) {
                $codes += $this->addonCodes($children);
            }
        }

        return $codes;
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function trust(string $code, array $manifest): array
    {
        $trust = [
            'verified' => false,
            'mode' => 'local',
        ];
        $keyId = $manifest['signature']['key_id'] ?? null;
        if (is_string($keyId) && $keyId !== '') {
            $trust['key_id'] = $keyId;
        }
        $state = $this->lifecycle->read($code);
        $verifiedAt = $state['updated_at'] ?? null;
        if (is_string($verifiedAt) && $verifiedAt !== '') {
            $trust['verified_at'] = $verifiedAt;
        }

        return $trust;
    }
}
