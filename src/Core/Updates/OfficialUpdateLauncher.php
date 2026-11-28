<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 发起进程只负责排队后退出，使 HTTP / CLI 入口的共享执行锁能够释放。 */
final class OfficialUpdateLauncher
{
    public function launch(string $taskId): void
    {
        app(UpdateRuntimeProbe::class)->assertUsable(config('ptadmin-updates.php_binary'));
        if (PHP_OS_FAMILY === 'Windows' || !preg_match('/^[a-f0-9-]{36}$/D', $taskId)) {
            throw new UpdateException('后台应用更新需要 Unix PHP CLI 和有效任务编号。', 'update_unavailable');
        }
        $command = implode(' ', array_map('escapeshellarg', [config('ptadmin-updates.php_binary'), base_path('artisan'), 'ptadmin:task:run', $taskId]));
        $environment = getenv();
        $environment['PTADMIN_UPDATE_COORDINATOR'] = '1';
        $pipes = [];
        $process = proc_open(['/bin/sh', '-c', $command.' </dev/null >/dev/null 2>&1 &'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        if (!is_resource($process)) {
            throw new UpdateException('无法启动后台更新协调进程。', 'update_failed');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_close($process) !== 0) {
            throw new UpdateException('后台更新协调进程启动失败。', 'update_failed');
        }
    }
}
