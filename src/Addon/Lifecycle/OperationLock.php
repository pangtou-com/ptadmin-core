<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use PTAdmin\Addon\Exception\AddonException;
use RuntimeException;

/**
 * 插件变更操作的进程级互斥锁。
 *
 * 同一插件的安装、升级、卸载、启停和前端同步不能并发执行；涉及全局注册快照的变更另受 Catalog 发布锁保护。
 */
final class OperationLock
{
    public function __construct(private string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create addon lock directory.');
        }
    }

    /**
     * 非阻塞获取插件锁；锁已被占用时立即返回 addon_busy，避免任务进程无限等待。
     *
     * @return resource 必须交给 release() 释放的文件句柄
     */
    public function acquire(string $code)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon code is invalid.', 'invalid_addon_code');
        }
        $handle = fopen($this->root.'/'.strtolower($code).'.lock', 'c+');
        if (false === $handle) {
            throw new RuntimeException('Unable to open addon operation lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new AddonException('Another addon operation is already running.', 'addon_busy');
        }
        return $handle;
    }

    /** @param resource $handle acquire() 返回的锁句柄 */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
