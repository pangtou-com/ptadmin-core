<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Catalog;

use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;

/**
 * 插件目录的统一发现入口。
 *
 * 扫描过程会重新校验插件包并推进生命周期状态，因此调用方不能把它当作无副作用的目录列表。
 * 后台列表保留运行时失败的 inactive 插件供诊断，Runtime 启动扫描则会重新尝试加载非用户禁用的插件。
 */
final class AddonCatalog
{
    public function __construct(
        private string $addonsPath,
        private ManifestVerifier $verifier,
        private LifecycleStore $lifecycle,
        private bool $requireSignature = true,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
    }

    /**
     * 扫描合法插件目录，返回已验证的插件描述和逐插件错误，单个插件失败不会中断其他插件。
     *
     * @param bool $forRuntime 是否用于 Runtime 启动；启用后会重试非用户禁用的 inactive 插件
     * @return array{addons:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    public function scan(bool $forRuntime = false): array
    {
        $addons = [];
        $errors = [];
        if (!is_dir($this->addonsPath)) {
            return ['addons' => [], 'errors' => []];
        }
        foreach (glob($this->addonsPath.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $code = basename($directory);
            if (str_starts_with($code, '.') || preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
                continue;
            }
            try {
                $current = $this->lifecycle->read($code);
                if (null === $current) {
                    $this->lifecycle->transition($code, 'discovered');
                    $this->lifecycle->transition($code, 'validating');
                } elseif (($current['state'] ?? null) === 'disposed') {
                    $this->lifecycle->transition($code, 'discovered');
                    $this->lifecycle->transition($code, 'validating');
                } elseif (in_array($current['state'] ?? null, ['ready', 'active', 'inactive', 'discovered'], true)) {
                    if (($current['state'] ?? null) === 'inactive' && ($current['disabled_by_user'] ?? false) === true) {
                        $manifest = $this->verifier->verify($directory, $this->requireSignature);
                        $addons[] = [
                            'code' => $code,
                            'version' => $manifest['version'],
                            'manifest' => $manifest,
                            'state' => 'inactive',
                            'disabled_by_user' => true,
                            'diagnostic_code' => 'user_disabled',
                        ];
                        continue;
                    }
                    if (($current['state'] ?? null) === 'inactive' && !$forRuntime) {
                        $manifest = $this->verifier->verify($directory, $this->requireSignature);
                        $addons[] = [
                            'code' => $code,
                            'version' => $manifest['version'],
                            'manifest' => $manifest,
                            'state' => 'inactive',
                            'disabled_by_user' => false,
                            'diagnostic_code' => $current['runtime_error_code'] ?? null,
                        ];
                        continue;
                    }
                    $this->lifecycle->transition($code, 'validating');
                }
                $manifest = $this->verifier->verify($directory, $this->requireSignature);
                $this->lifecycle->transition($code, 'loading');
                $this->lifecycle->transition($code, 'ready', [
                    'manifest' => $manifest,
                    'runtime_error_code' => null,
                    'runtime_error' => null,
                ]);
                $state = $this->lifecycle->read($code);
                if (($state['state'] ?? null) !== 'active') {
                    $this->lifecycle->transition($code, 'active');
                }
                $addons[] = [
                    'code' => $code,
                    'version' => $manifest['version'],
                    'manifest' => $manifest,
                    'state' => 'active',
                    'disabled_by_user' => false,
                    'diagnostic_code' => null,
                ];
            } catch (\Throwable $exception) {
                $errors[] = ['code' => $code, 'error_code' => $exception instanceof AddonException ? $exception->errorCode : 'addon_discovery_failed', 'message' => $exception->getMessage()];
            }
        }
        return ['addons' => $addons, 'errors' => $errors];
    }
}
