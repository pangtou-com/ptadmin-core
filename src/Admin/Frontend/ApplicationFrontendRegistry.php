<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Frontend;

use PTAdmin\Addon\Exception\AddonException;
use JsonException;
use PTAdmin\Addon\Frontend\PublishedBuildStyles;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use RuntimeException;

/**
 * 解析项目二次开发应用的根目录 frontend/frontend.json。
 *
 * 应用没有插件生命周期，但使用相同的 Host API、Runtime Profile 和入口声明格式；返回值使用通用 modules
 * 契约，owner_type=app、owner_code=host 保持与菜单和配置归属一致。
 */
final class ApplicationFrontendRegistry
{
    /** @param array<int,string> $supportedHostApis @param array<int,string> $supportedRuntimeProfiles @param array<int,string> $supportedCapabilities */
    public function __construct(
        private string $applicationPath,
        private string $publicRoot,
        private string $webPrefix,
        private string $apiPrefix,
        private array $supportedHostApis,
        private array $supportedRuntimeProfiles,
        private array $supportedCapabilities,
        private FrontendDevelopmentStore $development,
    ) {
        $this->applicationPath = rtrim($applicationPath, '/');
        $this->publicRoot = rtrim($publicRoot, '/');
        $this->apiPrefix = trim($apiPrefix, '/');
    }

    /** @param array<int,array<string,mixed>> $menus @return array{modules:array<int,array<string,mixed>>,errors:array<int,array<string,string>>} */
    public function resolve(array $menus): array
    {
        if (!$this->referencesApplicationPage($menus)) {
            return ['modules' => [], 'errors' => []];
        }
        try {
            $descriptor = $this->descriptor();
            if ($descriptor === null) {
                return ['modules' => [], 'errors' => []];
            }
            try {
                $development = $this->development->resolveInline('host', $descriptor['development'] ?? null);
            } catch (RuntimeException $exception) {
                throw new AddonException($exception->getMessage(), 'frontend_development_invalid', ['owner_type' => 'app', 'owner_code' => 'host']);
            }
            $artifact = $development === null ? $this->verifyPublishedArtifact() : null;
            $entries = $this->entries($descriptor, $artifact, $development);
            if ($entries === []) {
                return ['modules' => [], 'errors' => []];
            }

            return ['modules' => [[
                'owner_type' => 'app',
                'owner_code' => 'host',
                'code' => 'host',
                'version' => (string) ($descriptor['version'] ?? 'dev'),
                'enabled' => true,
                'api_base' => '/'.$this->apiPrefix.'/app',
                'entries' => $entries,
            ]], 'errors' => []];
        } catch (AddonException $exception) {
            return ['modules' => [], 'errors' => [[
                'code' => 'host',
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]]];
        }
    }

    /** @return array<string,mixed>|null */
    private function descriptor(): ?array
    {
        $path = $this->applicationPath.'/frontend/frontend.json';
        if (!is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AddonException('宿主应用 frontend.json 不是有效 JSON。', 'frontend_descriptor_invalid', ['path' => $path]);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AddonException('宿主应用 frontend.json 必须是对象。', 'frontend_descriptor_invalid');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @param array{artifact_sha256:string,files:list<array{path:string,size:int,sha256:string}>}|null $artifact
     * @param array<string,mixed>|null $development
     * @return list<array<string,mixed>>
     */
    private function entries(array $descriptor, ?array $artifact, ?array $development): array
    {
        $hostApi = (string) ($descriptor['host_api'] ?? '');
        if (!in_array($hostApi, $this->supportedHostApis, true)) {
            throw new AddonException('宿主应用前端 Host API 不受支持。', 'frontend_host_api_unsupported');
        }
        $runtimeProfile = $descriptor['runtime_profile'] ?? null;
        if (!is_string($runtimeProfile) || !in_array($runtimeProfile, $this->supportedRuntimeProfiles, true)) {
            throw new AddonException('宿主应用前端 Runtime Profile 不受支持。', 'frontend_runtime_profile_unsupported');
        }
        $declared = $descriptor['entries'] ?? null;
        if (!is_array($declared) || !array_is_list($declared)) {
            throw new AddonException('宿主应用前端 entries 必须是数组。', 'frontend_entries_invalid');
        }
        $resolved = [];
        $keys = [];
        foreach ($declared as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new AddonException('宿主应用前端入口必须是对象。', 'frontend_entry_invalid');
            }
            $key = (string) ($item['key'] ?? '');
            $title = (string) ($item['title'] ?? '');
            $entryPath = (string) ($item['entry'] ?? '');
            $sandbox = (string) ($item['sandbox'] ?? '');
            if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,190}$/', $key) !== 1 || $title === '' || in_array($key, $keys, true)) {
                throw new AddonException('宿主应用前端入口标识无效。', 'frontend_entry_invalid');
            }
            if (!in_array($sandbox, ['trusted', 'iframe'], true) || !$this->safePath($entryPath)) {
                throw new AddonException('宿主应用前端入口路径或 sandbox 无效。', 'frontend_entry_invalid');
            }
            if ($artifact !== null) {
                $published = $this->publicRoot.'/'.$this->webPrefix.'/modules/app/'.$entryPath;
                $real = realpath($published);
                $root = realpath($this->publicRoot.'/'.$this->webPrefix.'/modules/app');
                if ($real === false || $root === false || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || !is_file($real)
                    || !in_array($entryPath, array_column($artifact['files'], 'path'), true)) {
                    throw new AddonException('宿主应用前端入口尚未发布。', 'frontend_entry_unavailable', ['entry' => $entryPath]);
                }
            }
            if ($development !== null && $development['sandbox'] !== $sandbox) {
                throw new AddonException('宿主应用开发 sandbox 与入口声明不一致。', 'frontend_development_sandbox_mismatch');
            }
            $capabilities = $item['capabilities'] ?? [];
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new AddonException('宿主应用前端 capabilities 必须是字符串数组。', 'frontend_capabilities_invalid');
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || !in_array($capability, $this->supportedCapabilities, true)) {
                    throw new AddonException('宿主应用前端 capability 不受支持。', 'frontend_capability_unsupported');
                }
            }
            $keys[] = $key;
            $entry = [
                'key' => $key,
                'title' => $title,
                'entry' => './modules/app/'.$entryPath.'?v='.rawurlencode((string) ($descriptor['version'] ?? 'dev')),
                'sandbox' => $sandbox,
                'host_api' => $hostApi,
                'runtime_profile' => $runtimeProfile,
                'capabilities' => array_values(array_unique($capabilities)),
            ];
            if ($development !== null) {
                $entry['development'] = [
                    'load_mode' => 'development',
                    'entry_url' => $development['entry_url'],
                    'allowed_origin' => $development['allowed_origin'],
                    'dev_revision' => $development['dev_revision'],
                    'hmr' => $development['hmr'],
                    'source_maps' => $development['source_maps'],
                    'status' => $development['status'],
                ];
            } elseif ($artifact !== null) {
                $entry['styles'] = (new PublishedBuildStyles())->resolve(
                    $this->publicRoot.'/'.$this->webPrefix.'/modules/app', $entryPath, './modules/app',
                );
                $entry['trust'] = [
                    'verified' => true,
                    'manifest_sha256' => 'sha256:'.$artifact['artifact_sha256'],
                ];
            }
            $resolved[] = $entry;
        }

        return $resolved;
    }

    /** @param array<int,array<string,mixed>> $menus */
    private function referencesApplicationPage(array $menus): bool
    {
        foreach ($menus as $menu) {
            if (($menu['owner_type'] ?? null) === 'app' && ($menu['type'] ?? null) === 'page') {
                return true;
            }
            if (is_array($menu['children'] ?? null) && $this->referencesApplicationPage($menu['children'])) {
                return true;
            }
        }

        return false;
    }

    private function safePath(string $path): bool
    {
        return preg_match('/^[A-Za-z0-9]/', $path) === 1 && $this->safeArtifactPath($path);
    }

    private function safeArtifactPath(string $path): bool
    {
        $segments = explode('/', $path);

        return $path !== '' && preg_match('/^[A-Za-z0-9._][A-Za-z0-9._\/-]*$/', $path) === 1
            && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('', $segments, true) && !in_array('.', $segments, true) && !in_array('..', $segments, true);
    }

    /** @return array{artifact_sha256:string,files:list<array{path:string,size:int,sha256:string}>} */
    private function verifyPublishedArtifact(): array
    {
        $root = realpath($this->publicRoot.'/'.$this->webPrefix.'/modules/app');
        $path = $root === false ? '' : $root.'/.ptadmin-app-manifest.json';
        if ($root === false || !is_file($path)) {
            throw new AddonException('宿主应用前端发布清单不存在。', 'frontend_manifest_unavailable');
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AddonException('宿主应用前端发布清单无效。', 'frontend_manifest_invalid');
        }
        $files = $manifest['files'] ?? null;
        if (($manifest['manifest_version'] ?? null) !== 1 || !is_array($files) || !is_string($manifest['artifact_sha256'] ?? null)) {
            throw new AddonException('宿主应用前端发布清单头部无效。', 'frontend_manifest_invalid');
        }
        $previous = '';
        foreach ($files as $file) {
            $relative = $file['path'] ?? null;
            if (!is_string($relative) || !$this->safeArtifactPath($relative) || ($previous !== '' && strcmp($previous, $relative) >= 0)
                || !is_int($file['size'] ?? null) || !is_string($file['sha256'] ?? null)) {
                throw new AddonException('宿主应用前端发布清单文件项无效。', 'frontend_manifest_invalid');
            }
            $real = realpath($root.'/'.$relative);
            if ($real === false || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || !is_file($real)
                || filesize($real) !== $file['size'] || hash_file('sha256', $real) !== $file['sha256']) {
                throw new AddonException('宿主应用前端文件校验失败。', 'frontend_manifest_mismatch', ['path' => $relative]);
            }
            $previous = $relative;
        }
        $canonical = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals((string) $manifest['artifact_sha256'], hash('sha256', $canonical))) {
            throw new AddonException('宿主应用前端文件清单摘要不匹配。', 'frontend_manifest_mismatch');
        }

        return ['artifact_sha256' => $manifest['artifact_sha256'], 'files' => $files];
    }
}
