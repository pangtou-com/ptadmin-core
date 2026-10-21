<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Catalog;

use PTAdmin\Addon\Exception\AddonException;
use RuntimeException;

/** 原子替换保证读请求只看到完整快照；写锁保护生命周期操作与显式重建。 */
final class CatalogStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        try {
            $snapshot = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['format'] ?? null) !== 1
                || !is_array($snapshot['addons'] ?? null) || !array_is_list($snapshot['addons'])
                || !is_array($snapshot['errors'] ?? null) || !array_is_list($snapshot['errors'])) {
                throw new RuntimeException('Invalid catalog format.');
            }
            foreach ($snapshot['addons'] as $addon) {
                if (!is_array($addon) || !is_string($addon['code'] ?? null)
                    || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $addon['code']) !== 1
                    || !is_string($addon['state'] ?? null) || !is_array($addon['manifest'] ?? null)
                    || ($addon['manifest']['code'] ?? null) !== $addon['code']
                    || !is_string($addon['version'] ?? null)) {
                    throw new RuntimeException('Invalid catalog addon.');
                }
            }
            return ['addons' => $snapshot['addons'], 'errors' => $snapshot['errors']];
        } catch (\Throwable $exception) {
            throw new AddonException('插件注册快照损坏，请执行 php artisan ptadmin:addon:cache 重建。', 'addon_catalog_invalid');
        }
    }

    public function write(array $snapshot): array
    {
        $contents = json_encode(['format' => 1] + $snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $temporary = $this->path.'.tmp-'.bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to publish addon catalog: '.$this->path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $snapshot;
    }

    /** @return resource */
    public function lock(bool $wait = true)
    {
        $handle = fopen($this->path.'.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open addon catalog lock.');
        }
        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);
            if (!$wait) {
                throw new AddonException('另一个插件变更或缓存重建正在执行，请稍后重试。', 'addon_busy');
            }
            throw new RuntimeException('Unable to acquire addon catalog lock.');
        }
        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
