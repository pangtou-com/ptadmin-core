<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Catalog;

use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;

/** 请求读取已发布的插件清单；只有首次初始化、显式重建和生命周期操作发布快照。 */
final class AddonCatalog
{
    private readonly CatalogStore $store;

    public function __construct(
        private string $addonsPath,
        private readonly ManifestVerifier $verifier,
        private readonly LifecycleStore $lifecycle,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->store = new CatalogStore($lifecycle->catalogPath());
    }

    /**
     * 保留 scan 入口供现有调用方使用，但不再扫描目录或修改生命周期。
     * 缺少快照的旧安装只在锁内初始化一次；损坏快照必须显式重建。
     *
     * @return array{addons:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    public function scan(): array
    {
        $snapshot = $this->store->read();
        if ($snapshot !== null) {
            return $snapshot;
        }
        $lock = $this->store->lock();
        try {
            return $this->store->read() ?? $this->store->write($this->discover());
        } finally {
            $this->store->unlock($lock);
        }
    }

    /** 显式重建不改变启停状态，也不执行安装回调或同步业务资源。 */
    public function rebuild(): array
    {
        $lock = $this->store->lock();
        try {
            return $this->store->write($this->discover());
        } finally {
            $this->store->unlock($lock);
        }
    }

    /**
     * 在改动插件目录前锁定发布过程，防止重建读到安装中间状态或并行操作覆盖对方清单。
     *
     * @return resource
     */
    public function beginOperation()
    {
        $lock = $this->store->lock(false);
        try {
            if ($this->store->read() === null) {
                $this->store->write($this->discover());
            }
            return $lock;
        } catch (\Throwable $exception) {
            $this->store->unlock($lock);
            throw $exception;
        }
    }

    /** @param resource $lock */
    public function endOperation($lock): void
    {
        $this->store->unlock($lock);
    }

    /** 生命周期操作成功后、仍持有发布锁时，仅替换目标插件的已验证声明。 */
    public function publish(string $code): void
    {
        $snapshot = $this->store->read();
        if ($snapshot === null) {
            throw new AddonException('插件注册快照缺失，请执行 ptadmin:addon:cache。', 'addon_catalog_unavailable');
        }
        $snapshot['addons'] = array_values(array_filter($snapshot['addons'], static fn (array $addon): bool => $addon['code'] !== $code));
        $snapshot['errors'] = array_values(array_filter($snapshot['errors'], static fn (array $error): bool => $error['code'] !== $code));
        $state = $this->lifecycle->read($code);
        if ($state !== null && $state['state'] !== 'disposed') {
            if (!in_array($state['state'], ['active', 'inactive'], true) || !is_array($state['manifest'] ?? null)) {
                throw new AddonException('插件 '.$code.' 尚未完成生命周期操作，不能发布注册快照。', 'addon_catalog_not_ready');
            }
            $snapshot['addons'][] = $this->descriptor($code, $state['manifest'], $state);
        }
        usort($snapshot['addons'], static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        $this->store->write($snapshot);
    }

    private function discover(): array
    {
        $addons = [];
        $errors = [];
        foreach (glob($this->addonsPath.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $code = basename($directory);
            if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1 || preg_match('/\.(?:backup|uninstall)-[a-f0-9]+$/', $code) === 1) {
                continue;
            }
            try {
                $state = $this->lifecycle->read($code);
                if (($state['state'] ?? null) === 'disposed') {
                    continue;
                }
                $manifest = $this->verifier->verifyRuntime($directory);
                if ($manifest['code'] !== $code) {
                    throw new AddonException('The addon directory must match its lowercase manifest code.', 'addon_directory_mismatch');
                }
                if (is_array($state['manifest']['verified_files'] ?? null)) {
                    $manifest['verified_files'] = $state['manifest']['verified_files'];
                }
                $addons[] = $this->descriptor($code, $manifest, $state);
            } catch (\Throwable $exception) {
                $errors[] = ['code' => $code, 'error_code' => $exception instanceof AddonException ? $exception->errorCode : 'addon_discovery_failed', 'message' => $exception->getMessage()];
            }
        }
        return ['addons' => $addons, 'errors' => $errors];
    }

    private function descriptor(string $code, array $manifest, ?array $state): array
    {
        return [
            'code' => $code,
            'version' => $manifest['version'],
            'manifest' => $manifest,
            'state' => $state['state'] ?? 'active',
            'disabled_by_user' => $state['disabled_by_user'] ?? false,
            'diagnostic_code' => ($state['disabled_by_user'] ?? false) ? 'user_disabled' : ($state['runtime_error_code'] ?? null),
        ];
    }
}
