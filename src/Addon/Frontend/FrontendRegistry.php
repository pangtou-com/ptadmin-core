<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use JsonException;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Manifest\TrustPolicy;

/**
 * 为当前管理员解析可加载的插件前端入口。
 *
 * Registry 同时校验插件 active 状态、菜单可见性、权限、Host API、sandbox、发布文件摘要和 trusted 策略，
 * 最终只向 Session 暴露满足全部条件的最小前端描述，不返回磁盘路径或原始 manifest。
 */
final class FrontendRegistry
{
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
        private string $addonsPath,
        private string $publicRoot,
        private string $webPrefix,
        private array $supportedHostApis,
        private array $supportedRuntimeProfiles,
        private array $supportedCapabilities,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->publicRoot = rtrim($publicRoot, '/');
    }

    /**
     * 根据当前管理员权限和可见菜单生成插件模块列表；单个插件声明无效时只记录该插件错误。
     *
     * @param array<int, string> $permissions
     * @param array<int, array<string, mixed>> $menus
     * @return array{plugins:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
     */
    public function resolve(array $permissions, array $menus): array
    {
        $result = $this->discovery->scan();
        $plugins = [];
        $errors = $result['errors'];
        $visibleMenuKeys = $this->menuKeys($menus);
        $registeredKeys = [];

        foreach ($result['addons'] as $addon) {
            if (($addon['state'] ?? null) !== 'active') {
                continue;
            }
            $code = (string) ($addon['code'] ?? '');
            $manifest = is_array($addon['manifest'] ?? null) ? $addon['manifest'] : [];
            try {
                $entries = $this->resolveAddonEntries(
                    $code,
                    $manifest,
                    $permissions,
                    $visibleMenuKeys,
                    $registeredKeys,
                );
                if ($entries === []) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $registeredKeys[] = $entry['key'];
                }
                $plugins[] = [
                    'code' => $code,
                    'version' => (string) ($addon['version'] ?? ''),
                    'enabled' => true,
                    'entries' => $entries,
                ];
            } catch (AddonException $exception) {
                $errors[] = [
                    'code' => $code,
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
     * @param array<int, string> $permissions
     * @param array<int, string> $visibleMenuKeys
     * @param array<int, string> $registeredKeys
     * @return array<int, array<string, mixed>>
     */
    private function resolveAddonEntries(
        string $code,
        array $manifest,
        array $permissions,
        array $visibleMenuKeys,
        array $registeredKeys,
    ): array {
        $descriptor = $this->descriptor($code);
        if (null === $descriptor) {
            return [];
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

            $requiredPermissions = $this->stringList($declaredEntry['permissions'] ?? null, 'frontend_permissions_invalid');
            if ($requiredPermissions === []) {
                throw new AddonException('The addon frontend entry must declare permissions.', 'frontend_permissions_required');
            }
            if (!in_array($key, $visibleMenuKeys, true) || !$this->allows($permissions, $requiredPermissions)) {
                continue;
            }

            $sandbox = (string) ($declaredEntry['sandbox'] ?? '');
            if (!in_array($sandbox, ['trusted', 'iframe'], true)) {
                throw new AddonException('The addon frontend sandbox is invalid.', 'frontend_sandbox_invalid');
            }
            $entryPath = (string) ($declaredEntry['entry'] ?? '');
            $this->assertEntryPath($entryPath, $sandbox);
            $publishedPath = $this->publicRoot.'/'.$this->webPrefix.'/modules/'.$code.'/'.$entryPath;
            $realPublishedPath = realpath($publishedPath);
            $moduleRoot = realpath($this->publicRoot.'/'.$this->webPrefix.'/modules/'.$code);
            if (!is_string($realPublishedPath) || !is_string($moduleRoot)
                || !str_starts_with($realPublishedPath, $moduleRoot.DIRECTORY_SEPARATOR)
                || !is_file($realPublishedPath)) {
                throw new AddonException('The addon frontend entry is not published.', 'frontend_entry_unavailable', ['entry' => $entryPath]);
            }
            $digest = hash_file('sha256', $realPublishedPath);
            if (!is_string($digest)) {
                throw new AddonException('The addon frontend entry cannot be hashed.', 'frontend_entry_hash_failed');
            }
            $expectedDigest = $this->verifiedFileDigest($manifest, 'Frontend/dist/'.$entryPath);
            if (null === $expectedDigest || !hash_equals($expectedDigest, $digest)) {
                throw new AddonException(
                    'The published addon frontend entry does not match the verified package.',
                    'frontend_entry_digest_mismatch',
                    ['entry' => $entryPath]
                );
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
                'entry' => './modules/'.rawurlencode($code).'/'.$entryPath.'?v=sha256:'.$digest,
                'sandbox' => $sandbox,
                'host_api' => $hostApi,
            ];
            if ($capabilities !== []) {
                $entry['capabilities'] = $capabilities;
            }
            if ($sandbox === 'trusted') {
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

        return $resolved;
    }

    /** @return array<string, mixed>|null */
    private function descriptor(string $code): ?array
    {
        $path = $this->addonsPath.'/'.$code.'/Frontend/frontend.json';
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

    /** @param array<string, mixed> $manifest */
    private function verifiedFileDigest(array $manifest, string $path): ?string
    {
        $files = $manifest['verified_files'] ?? null;
        if (!is_array($files)) {
            return null;
        }
        foreach ($files as $file) {
            if (is_array($file) && ($file['path'] ?? null) === $path && is_string($file['sha256'] ?? null)) {
                return strtolower($file['sha256']);
            }
        }

        return null;
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

    /** @param array<int, string> $permissions @param array<int, string> $required */
    private function allows(array $permissions, array $required): bool
    {
        if (in_array('*', $permissions, true)) {
            return true;
        }
        foreach ($required as $permission) {
            if (in_array($permission, $permissions, true)) {
                continue;
            }
            $allowedByResourceWildcard = false;
            foreach ($permissions as $granted) {
                if (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                    $allowedByResourceWildcard = true;
                    break;
                }
            }
            if (!$allowedByResourceWildcard) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $menus @return array<int, string> */
    private function menuKeys(array $menus): array
    {
        $keys = [];
        foreach ($menus as $menu) {
            $key = $menu['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
            $children = $menu['children'] ?? null;
            if (is_array($children)) {
                $keys = array_merge($keys, $this->menuKeys($children));
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function trust(string $code, array $manifest): array
    {
        $trust = [
            'verified' => true,
            'manifest_sha256' => $this->verifier->manifestSha256($manifest),
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
