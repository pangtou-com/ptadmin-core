<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Runtime\RuntimeLifecycleValidator;
use PTAdmin\Addon\Runtime\AddonAutoloader;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use RuntimeException;

/**
 * 编排已安装插件的启用和禁用流程。
 *
 * 状态切换会同步或停用 Schema、配置和菜单资源，调用插件生命周期回调，清理 Runtime 缓存，并在最终状态上校验 Runtime 绑定。
 * 操作失败时尽量恢复资源和生命周期快照，原始异常始终优先返回给调用方。
 */
final class StateManager
{
    public function __construct(
        private string $addonsPath,
        private ManifestVerifier $verifier,
        private LifecycleStore $lifecycle,
        private OperationLock $locks,
        bool $requireSignature = true,
        private ?ResourceSynchronizerInterface $resourceSynchronizer = null,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?RuntimeLifecycleValidator $runtimeValidator = null,
        private ?LifecycleRegistrar $lifecycleRegistrar = null,
        private ?AddonAutoloader $autoloader = null,
        private ?AddonCatalog $catalog = null,
    ) {
        // 保留已有构造参数位置；签名在安装阶段校验，启停读取已安装插件的运行时清单。
        unset($requireSignature);
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->autoloader ??= new AddonAutoloader();
        $this->catalog ??= new AddonCatalog($this->addonsPath, $this->verifier, $this->lifecycle);
    }

    /**
     * 禁用插件并停用其资源；插件目录、配置值和业务数据继续保留。
     *
     * @param string $code
     * @return array<string,mixed>
     * @throws \JsonException
     * @throws \Throwable
     */
    public function disable(string $code): array
    {
        return $this->change($code, false);
    }

    /**
     * 读取插件运行时 manifest、恢复资源并在 Runtime 校验通过后标记为 active。
     *
     * @param string $code
     * @return array<string,mixed>
     * @throws \JsonException
     * @throws \Throwable
     */
    public function enable(string $code): array
    {
        return $this->change($code, true);
    }

    /**
     * 在插件级互斥锁内执行状态切换，确保资源状态、生命周期和 Runtime 缓存按同一次操作更新。
     *
     * @param string $code
     * @param bool $enabled
     * @return array<string,mixed>
     * @throws \JsonException
     * @throws \Throwable
     */
    private function change(string $code, bool $enabled): array
    {
        $code = strtolower($code);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon code is invalid.', 'invalid_addon_code');
        }
        $directory = $this->resolveInstalledAddonDirectory($code);
        if (null === $directory) {
            throw new AddonException('The addon is not installed.', 'addon_not_installed');
        }
        $lock = $this->locks->acquire($code);
        $previousMapping = null;
        $mappingCaptured = false;
        $runtimeValidationStarted = false;
        $resourcesChanged = false;
        try {
            $catalogLock = $this->catalog->beginOperation();
            $manifest = $this->verifier->verifyRuntime($directory);
            $previousMapping = $this->autoloader->snapshot($code);
            $mappingCaptured = true;
            $this->autoloader->register($directory, $manifest);
            $this->runtimeCache?->clear();
            $current = $this->lifecycle->read($code);
            if (!$enabled) {
                $this->lifecycleRegistrar?->invoke('disable', $directory, $code, $manifest);
                $this->resourceSynchronizer?->deactivate($code);
                $resourcesChanged = true;
                $state = (string) ($current['state'] ?? 'discovered');
                if ($current === null) {
                    $this->lifecycle->transition($code, 'discovered');
                    $state = 'discovered';
                }
                if ($state === 'discovered') {
                    $this->lifecycle->transition($code, 'validating');
                    $state = 'validating';
                }
                if ($state === 'validating') {
                    $this->lifecycle->transition($code, 'loading');
                    $state = 'loading';
                }
                if ($state === 'loading') {
                    $this->lifecycle->transition($code, 'ready');
                }
                $result = $this->lifecycle->transition($code, 'inactive', [
                    'disabled_by_user' => true,
                    'runtime_error' => null,
                    'version' => $manifest['version'],
                    'manifest' => $manifest,
                ]);
                $runtimeValidationStarted = true;
                $this->runtimeValidator?->assertDeactivated($code);
            } else {
                $this->resourceSynchronizer?->sync($directory, $code);
                $resourcesChanged = true;
                $this->lifecycleRegistrar?->invoke('enable', $directory, $code, $manifest);
                $state = (string) ($current['state'] ?? 'discovered');
                if ($current === null || $state === 'disposed') {
                    if ($state === 'disposed') {
                        $this->lifecycle->transition($code, 'discovered');
                    } else {
                        $this->lifecycle->transition($code, 'discovered');
                    }
                    $state = 'discovered';
                }
                if ($state === 'discovered') {
                    $this->lifecycle->transition($code, 'validating');
                    $state = 'validating';
                }
                if ($state === 'validating') {
                    $this->lifecycle->transition($code, 'loading');
                    $state = 'loading';
                }
                if ($state === 'loading') {
                    $this->lifecycle->transition($code, 'ready');
                }
                $result = $this->lifecycle->transition($code, 'active', [
                    'disabled_by_user' => false,
                    'runtime_error' => null,
                    'version' => $manifest['version'],
                    'manifest' => $manifest,
                ]);
                $runtimeValidationStarted = true;
                $this->runtimeValidator?->assertActivated($directory, $code, $manifest);
            }
            $this->catalog->publish($code);
            return ['code' => $code, 'enabled' => $enabled, 'state' => $result['state'], 'version' => $manifest['version']];
        } catch (\Throwable $exception) {
            if ($runtimeValidationStarted || $resourcesChanged) {
                try {
                    if ($enabled) {
                        $this->resourceSynchronizer?->deactivate($code);
                    } else {
                        $this->resourceSynchronizer?->sync($directory, $code);
                    }
                } catch (\Throwable) {
                    // 回滚同步失败时保留原始 Runtime 异常，下一次生命周期操作仍会重新同步资源。
                }
                try {
                    $this->lifecycle->restore($code, $current ?? null);
                } catch (\Throwable) {
                    // 生命周期快照恢复失败不能覆盖触发回滚的原始 Runtime 异常。
                }
            }
            if ($mappingCaptured) {
                $this->autoloader->restore($code, $previousMapping);
            }
            throw $exception;
        } finally {
            if (isset($catalogLock) && is_resource($catalogLock)) {
                $this->catalog->endOperation($catalogLock);
            }
            $this->locks->release($lock);
        }
    }

    /** Resolve the directory's actual spelling before loading plugin PHP files. */
    private function resolveInstalledAddonDirectory(string $code): ?string
    {
        if (is_dir($this->addonsPath)) {
            foreach (glob($this->addonsPath.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (basename($directory) !== strtolower($code)) {
                    continue;
                }
                $resolved = realpath($directory);
                if (is_string($resolved) && is_dir($resolved)) {
                    return $resolved;
                }
            }
        }

        $direct = realpath($this->addonsPath.'/'.strtolower($code));

        return is_string($direct) && is_dir($direct) ? $direct : null;
    }
}
