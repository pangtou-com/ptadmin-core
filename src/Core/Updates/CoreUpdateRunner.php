<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;

/** 私有目录中的独立 PHP 进程；执行期间不加载正在替换的 vendor。 */
final class CoreUpdateRunner
{
    private array $plan;
    private int $lastHeartbeat = 0;

    public function __construct(private readonly string $directory)
    {
        $this->plan = UpdateFiles::read($directory.'/plan.json');
    }

    public function execute(): array
    {
        $root = $this->plan['root'];
        $recovery = new UpdateRecovery($this->directory);
        try {
            $this->event('preparing_dependencies', 15, '正在隔离目录准备 Core 及完整依赖。');
            $stage = $this->directory.'/project';
            UpdateFiles::directory($stage);
            $composer = UpdateFiles::read($root.'/composer.json');
            $composer['require']['ptadmin/core'] = $this->plan['version'];
            UpdateFiles::write($stage.'/composer.json', $composer);
            UpdateFiles::copy($root.'/composer.lock', $stage.'/composer.lock');
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
                foreach ($composer['autoload'][$kind] ?? [] as $paths) {
                    foreach ((array) $paths as $path) {
                        $path = rtrim($path, '/');
                        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')
                            || str_contains($path, '*') || in_array(explode('/', $path)[0], ['vendor', 'storage', '.git'], true)) {
                            throw new RuntimeException('项目自动加载路径不支持隔离构建，请通过项目部署流程更新。');
                        }
                        if (file_exists($root.'/'.$path)) {
                            UpdateFiles::copy($root.'/'.$path, $stage.'/'.$path);
                        }
                    }
                }
            }
            $base = [$this->plan['php'], $this->plan['composer']];
            $this->command([...$base, 'update', 'ptadmin/core', '--with-all-dependencies', '--no-dev', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-interaction', '--no-progress', '--no-audit'], $stage, 'Composer 依赖准备');
            $this->command([...$base, 'check-platform-reqs', '--no-dev'], $stage, 'PHP 扩展及运行平台校验');
            chmod($stage.'/composer.json', fileperms($root.'/composer.json') & 0777);
            chmod($stage.'/composer.lock', fileperms($root.'/composer.lock') & 0777);
            $lock = UpdateFiles::read($stage.'/composer.lock');
            foreach ($lock['packages'] as $package) {
                if (($package['type'] ?? '') === 'composer-plugin') {
                    throw new RuntimeException('目标依赖需要 Composer 插件，请通过部署流程更新。');
                }
            }
            $packages = array_column($lock['packages'], null, 'name');
            $core = $packages['ptadmin/core'] ?? [];
            if (ltrim($core['version'] ?? '', 'v') !== $this->plan['version']
                || ($core['source']['reference'] ?? '') !== $this->plan['git_commit']) {
                throw new RuntimeException('Composer 解析的 Core 版本或提交与官方记录不一致。');
            }
            // 准备期间项目可能被外部部署修改，不允许以旧快照覆盖新的锁文件。
            foreach ($this->plan['inputs'] as $path => $sha) {
                if (!is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                    throw new RuntimeException('准备期间项目依赖文件变化，请重新发起更新。');
                }
            }
            $this->command([$this->plan['php'], $root.'/artisan', 'ptadmin:updates:verify-target', 'core', $this->plan['version'], '--commit='.$this->plan['git_commit']], $root, '切换前发布状态与环境复核');
            $this->event('replacing', 65, '正在保存备份并切换依赖，应用将短暂进入维护状态。中断恢复入口：'.$this->directory.'/recover.php');
            $framework = $this->plan['storage'].'/framework';
            UpdateFiles::directory($framework);
            $maintenance = [$framework.'/maintenance.php', $framework.'/down'];
            foreach ($maintenance as $path) {
                if (file_exists($path)) {
                    throw new RuntimeException('应用已进入其他维护流程，停止更新。');
                }
            }
            $metadata = ['component' => 'core', 'version' => $this->plan['version'], 'maintenance_files' => $maintenance, 'task_directory' => $this->plan['task_directory']];
            UpdateFiles::write($this->directory.'/journal.json', $metadata + ['status' => 'entering_maintenance', 'database_started' => false, 'entries' => []]);
            if (file_put_contents($maintenance[0], '<?php http_response_code(503); header("Retry-After: 5"); header("X-PTAdmin-Update: maintenance"); echo "系统正在更新，请稍后刷新。"; exit;') === false) {
                throw new RuntimeException('无法进入维护状态。');
            }
            UpdateFiles::write($maintenance[1], ['time' => time(), 'retry' => 60]);
            // 使用空的新缓存目录启动新 vendor；旧缓存随目标目录一并备份。
            UpdateFiles::directory($this->directory.'/cache');
            chmod($this->directory.'/cache', fileperms($root.'/bootstrap/cache') & 0777);
            $entries = [];
            foreach (['vendor', 'composer.json', 'composer.lock'] as $name) {
                $entries[] = ['source' => $stage.'/'.$name, 'target' => $root.'/'.$name];
            }
            $entries[] = ['source' => $this->directory.'/cache', 'target' => $root.'/bootstrap/cache'];
            $recovery->switch($entries, $metadata);
            foreach (['config:clear', 'route:clear', 'event:clear', 'view:clear', 'package:discover'] as $command) {
                $this->command([$this->plan['php'], $root.'/artisan', $command, '--no-interaction'], $root, '刷新应用缓存');
            }
            $recovery->stage('migrating', ['database_started' => true]);
            $this->event('migrate', 80, '正在执行数据库迁移与资源同步；失败时需按数据库备份恢复。');
            $this->command([$this->plan['php'], $root.'/artisan', 'migrate', '--force', '--no-interaction'], $root, '数据库迁移');
            $this->command([$this->plan['php'], $root.'/artisan', 'ptadmin:sync', '--no-interaction'], $root, '应用及插件资源同步');
            if ($this->plan['config_cached']) {
                $this->command([$this->plan['php'], $root.'/artisan', 'config:cache'], $root, '配置缓存重建');
            }
            foreach ($this->plan['rebuild_caches'] ?? [] as $command) {
                $this->command([$this->plan['php'], $root.'/artisan', $command], $root, '应用缓存重建');
            }
            $this->command([$this->plan['php'], $this->directory.'/health.php', $root, $this->plan['version']], $root, '应用启动与版本检查');
            $this->command([$this->plan['php'], $root.'/artisan', 'queue:restart'], $root, '通知队列进程重启');
            // 数据库步骤完成后仍保留备份；恢复旧文件前必须先恢复匹配的数据库。
            foreach ($maintenance as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('更新完成，但无法退出维护状态。');
                }
            }
            $recovery->stage('completed');
            $result = ['component' => 'core', 'version' => $this->plan['version'], 'reload_required' => true,
                'message' => 'Core 已更新，队列已收到重启通知；使用常驻 HTTP 进程时请按部署配置重启。'];
            $this->finish('succeeded', $result);
            return $result;
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            if (is_file($this->directory.'/journal.json')) {
                try {
                    $recovery->restore();
                    $message .= ' 原依赖和文件已恢复。';
                } catch (\Throwable $restoreError) {
                    $message .= ' '.$restoreError->getMessage().' 恢复入口：'.$this->directory.'/recover.php';
                }
            }
            $result = ['message' => $message];
            if (is_file($this->directory.'/journal.json')) {
                $journal = $recovery->read();
                if (!in_array($journal['status'], ['completed', 'restored'], true)) {
                    $result['recovery_command'] = 'php '.escapeshellarg($this->directory.'/recover.php').' restore'
                        .(($journal['database_started'] ?? false) ? ' --database-restored' : '');
                }
            }
            $this->finish('failed', $result);
            throw new RuntimeException($message, previous: $error);
        }
    }

    private function command(array $command, string $cwd, string $label): void
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException($label.'无法启动。');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = time();
        $exit = -1;
        try {
            do {
                // 子进程原始输出不写入公开任务日志，防止 Composer 私有仓库凭据泄露。
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (time() - $started > $this->plan['timeout']) {
                    throw new RuntimeException($label.'超时。');
                }
                if (time() - $this->lastHeartbeat >= 5) {
                    $this->event(null, null, $label.'进行中。');
                }
                usleep(100000);
            } while (true);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
        }
        if (($exit >= 0 ? $exit : $closed) !== 0) {
            throw new RuntimeException($label.'失败，请部署负责人核对该步骤的环境与依赖。');
        }
    }

    /** 与任务存储使用相同锁和索引失效标记，父 HTTP 进程退出后仍能记录结果。 */
    private function event(?string $stage, ?int $progress, string $message, ?string $status = null): void
    {
        $task = $this->plan['task_directory'];
        $stateLock = fopen($task.'/.state.lock', 'c+');
        $indexLock = fopen(dirname($task).'/.index.lock', 'c+');
        if ($stateLock === false || $indexLock === false) {
            throw new RuntimeException('无法锁定更新任务记录。');
        }
        try {
            flock($stateLock, LOCK_EX);
            flock($indexLock, LOCK_EX);
            $state = UpdateFiles::read($task.'/state.json');
            if ($state['status'] !== 'running') {
                throw new RuntimeException('更新任务已停止，不能继续修改文件。');
            }
            $state['stage'] = $stage ?? $state['stage'];
            $state['progress'] = $progress ?? $state['progress'];
            $state['status'] = $status ?? $state['status'];
            $state['message'] = $message;
            $state['updated_at'] = $state['heartbeat_at'] = gmdate('c');
            if ($status !== null) {
                $state['finished_at'] = gmdate('c');
                $state['error_code'] = $status === 'failed' ? 'update_failed' : null;
                $state['error_message'] = $status === 'failed' ? $message : null;
            }
            file_put_contents(dirname($task).'/.index-dirty', '1');
            UpdateFiles::write($task.'/state.json', $state);
            $event = array_intersect_key($state, array_flip(['task_id', 'type', 'stage', 'status', 'progress', 'message', 'request_id']));
            file_put_contents($task.'/events.jsonl', json_encode($event + ['created_at' => gmdate('c'), 'data' => []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            $this->lastHeartbeat = time();
        } finally {
            flock($indexLock, LOCK_UN);
            fclose($indexLock);
            flock($stateLock, LOCK_UN);
            fclose($stateLock);
        }
    }

    private function finish(string $status, array $result): void
    {
        UpdateFiles::write($this->directory.'/result.json', ['status' => $status, 'data' => $result]);
        UpdateFiles::write($this->plan['task_directory'].'/result.json', ['status' => $status, 'data' => $result, 'completed_at' => gmdate('c')]);
        $this->event($status === 'succeeded' ? 'completed' : null, $status === 'succeeded' ? 100 : null, $result['message'], $status);
    }
}
