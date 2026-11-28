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
        $runtimeLock = null;
        try {
            $this->event('verifying_target', 50, '正在复核已准备的完整目标与来源依赖。');
            $stage = $this->directory.'/project';
            $target = UpdateFiles::read($stage.'/'.PackageManifest::IDENTITY);
            PackageManifest::verify($stage, $target, false);
            $this->verifySource($root);
            if ($target['version'] !== $this->plan['version'] || $target['core_commit'] !== $this->plan['git_commit']) {
                throw new RuntimeException('完整目标与官方发行身份不一致。');
            }
            // 准备期间项目可能被外部部署修改，不允许以旧快照覆盖新的锁文件。
            foreach ($this->plan['inputs'] as $path => $sha) {
                if (!is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                    throw new RuntimeException('准备期间项目依赖文件变化，请重新发起更新。');
                }
            }
            $verifyCommand = [$this->plan['php'], $root.'/artisan', 'ptadmin:updates:verify-target', 'core', $this->plan['version'], '--commit='.$this->plan['git_commit'], '--build='.$target['build_id'], '--artifact='.($this->plan['artifact']['sha256'] ?? '')];
            $this->command($verifyCommand, $root, '切换前发布状态与环境复核');
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
            $runtimeLock = fopen($framework.'/ptadmin-runtime.lock', 'c+');
            if ($runtimeLock === false) {
                throw new RuntimeException('无法打开应用执行锁。');
            }
            $deadline = microtime(true) + 60;
            while (!flock($runtimeLock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('旧请求或常驻命令尚未退出，请暂停队列、调度和长任务后重试。');
                }
                if (time() - $this->lastHeartbeat >= 5) {
                    $this->event('draining', 60, '等待已进入的请求和命令完成。');
                }
                usleep(100000);
            }
            // 取得独占锁后复核来源、宿主与输入，防止准备期间的部署或业务写入被覆盖。
            $this->verifySource($root);
            foreach ($target['host_files'] as $path => $sha) {
                if (!is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                    throw new RuntimeException('目标宿主前置条件发生变化：'.$path);
                }
            }
            if (isset($this->plan['shell_input']) && PackageManifest::digest(PackageManifest::files($root, [$this->plan['shell_input']['path']])) !== $this->plan['shell_input']['sha256']) {
                throw new RuntimeException('准备期间后台界面或用户前端资源变化，请重新发起更新。');
            }
            foreach ($this->plan['inputs'] as $path => $sha) {
                if (!is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                    throw new RuntimeException('切换前项目依赖输入已变化。');
                }
            }
            $this->command([...$verifyCommand, '--publication-only'], $root, '排空后发布状态复核');
            // 使用空的新缓存目录启动新 vendor；旧缓存随目标目录一并备份。
            UpdateFiles::directory($this->directory.'/cache');
            chmod($this->directory.'/cache', fileperms($root.'/bootstrap/cache') & 0777);
            $entries = [];
            foreach (['vendor', 'composer.json', 'composer.lock', PackageManifest::IDENTITY] as $name) {
                $entries[] = ['source' => $stage.'/'.$name, 'target' => $root.'/'.$name,
                    'allow_create' => $name === PackageManifest::IDENTITY && ($this->plan['composer_source'] ?? null) !== null];
            }
            $entries[] = ['source' => $this->directory.'/cache', 'target' => $root.'/bootstrap/cache'];
            if (isset($this->plan['shell_target'])) {
                $entries[] = ['source' => $this->directory.'/shell', 'target' => $this->plan['shell_target']];
            }
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
        } finally {
            if (is_resource($runtimeLock)) {
                flock($runtimeLock, LOCK_UN);
                fclose($runtimeLock);
            }
        }
    }

    private function verifySource(string $root): void
    {
        $source = $this->plan['composer_source'] ?? null;
        if ($source === null) {
            PackageManifest::verify($root, UpdateFiles::read($root.'/'.PackageManifest::IDENTITY));
            return;
        }
        $identity = is_file($root.'/'.PackageManifest::IDENTITY) ? hash_file('sha256', $root.'/'.PackageManifest::IDENTITY) : null;
        if ($identity !== $source['identity_sha256']) {
            throw new RuntimeException('准备期间官方构建身份发生变化。');
        }
        PackageManifest::verifyComposerInstallation($root, $source['target'], $source['files']);
    }

    private function command(array $command, string $cwd, string $label): void
    {
        $pipes = [];
        $environment = getenv();
        $environment['PTADMIN_UPDATE_COORDINATOR'] = '1';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
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
                // 子进程原始输出不写入公开任务日志，防止业务命令的敏感信息泄露。
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
