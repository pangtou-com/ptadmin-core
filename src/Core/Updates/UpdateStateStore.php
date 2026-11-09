<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Illuminate\Filesystem\Filesystem;

/** 更新目录、检查状态与公告已读保存在私有文件中，不创建更新任务表。 */
final class UpdateStateStore
{
    public function read(): array
    {
        $path = $this->directory().'/state.json';
        if (!is_file($path)) {
            return [];
        }
        try {
            if (!is_readable($path) || filesize($path) > 20 * 1024 * 1024) {
                throw new \RuntimeException();
            }
            $state = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \RuntimeException();
            }
            unset($state['next_check_at'], $state['last_scheduled_at']);
            // 旧目录缓存只保留签名字节；切换后重新联网获取 document，保留公告已读记录。
            if (isset($state['envelope']['signed_payload']) && !isset($state['envelope']['document'])) {
                unset($state['envelope'], $state['last_success_at'], $state['last_attempt_at'],
                    $state['error'], $state['failure_count']);
            }
            return $state;
        } catch (\Throwable) {
            throw new UpdateException('本地更新缓存不可读取或已损坏，请部署负责人检查 storage/app/ptadmin/updates 的权限和备份；不能将此状态视为已是最新。', 'cache_unavailable');
        }
    }

    public function write(array $state): void
    {
        try {
            (new Filesystem())->replace($this->directory().'/state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0600);
        } catch (\Throwable) {
            throw new UpdateException('无法保存版本检查结果，请部署负责人为当前运行账号配置更新缓存目录的最小必要写权限。', 'cache_not_writable');
        }
    }

    public function locked(callable $operation): mixed
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UpdateException('无法创建更新缓存目录，请部署负责人检查 storage/app/ptadmin 的写权限。', 'cache_not_writable');
        }
        $lock = @fopen($directory.'/check.lock', 'c');
        if ($lock === false) {
            throw new UpdateException('无法打开更新检查锁，请部署负责人修复缓存目录写权限。', 'cache_not_writable');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new UpdateException('已有版本检查正在执行，请稍后查看结果。', 'check_running');
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(): string
    {
        return config('ptadmin-updates.path');
    }
}
