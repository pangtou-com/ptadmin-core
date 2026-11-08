<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks\Executors;

use PTAdmin\Core\Tasks\{TaskExecutorInterface, TaskManager, TaskStore};
use PTAdmin\Core\Updates\{OfficialUpdateInstaller, OfficialUpdateWorkspace, UpdateEnvironment, UpdateException, UpdateFiles};
use Symfony\Component\Process\Process;

final class CoreUpdateExecutor implements TaskExecutorInterface
{
    public function __construct(
        private readonly OfficialUpdateInstaller $installer,
        private readonly OfficialUpdateWorkspace $workspace,
        private readonly UpdateEnvironment $environment,
        private readonly TaskStore $store,
    ) {
    }

    public function execute(array $task, TaskManager $tasks): array
    {
        if (($task['payload']['official'] ?? false) !== true || ($task['payload']['database_backup_confirmed'] ?? false) !== true) {
            throw new UpdateException('Core 更新必须从官方更新入口发起并确认数据库备份。', 'invalid_update');
        }
        $prepared = $this->workspace->run($task['task_id'], function (string $directory) use ($task, $tasks): array {
            $tasks->heartbeat($task['task_id'], 'checking', 5, '正在复核 Core 发行与部署条件。');
            $release = $this->installer->resolve('core', $task['payload']['version']);
            $composer = config('ptadmin-updates.composer_path') ?: $this->environment->findComposer();
            $composer ??= is_file(base_path('composer.phar')) ? base_path('composer.phar') : null;
            if ($composer === null || stat(base_path('vendor'))['dev'] !== stat($directory)['dev']) {
                throw new UpdateException('依赖和备份目录必须在同一文件系统，并提供可运行的 Composer。', 'update_unavailable');
            }
            $php = config('ptadmin-updates.php_binary');
            $probe = new Process([$php, '-r', 'echo json_encode([PHP_SAPI, PHP_VERSION, get_loaded_extensions()]);']);
            $probe->setTimeout(10)->run();
            $runtime = json_decode($probe->getOutput(), true);
            $extensions = array_values(array_diff(get_loaded_extensions(), ['Zend OPcache', 'xdebug']));
            if (!$probe->isSuccessful() || !is_array($runtime) || ($runtime[0] ?? null) !== 'cli'
                || ($runtime[1] ?? null) !== PHP_VERSION || array_diff($extensions, $runtime[2] ?? []) !== []
                || array_diff($runtime[2] ?? [], [...$extensions, 'Zend OPcache', 'xdebug']) !== []) {
                throw new UpdateException('PHP CLI 与当前应用的 PHP 版本或扩展不一致，请通过部署流程更新。', 'update_unavailable');
            }
            $inputs = [];
            foreach (['composer.json', 'composer.lock'] as $file) {
                $inputs[$file] = hash_file('sha256', base_path($file));
            }
            UpdateFiles::write($directory.'/plan.json', ['root' => base_path(), 'storage' => storage_path(), 'version' => $release['version'],
                'git_commit' => $release['git_commit'], 'php' => $php, 'composer' => $composer, 'inputs' => $inputs,
                'timeout' => (int) config('ptadmin-updates.process_timeout'), 'task_directory' => $this->store->taskDirectory($task['task_id']),
                'rebuild_caches' => array_values(array_filter([app()->routesAreCached() ? 'route:cache' : null, app()->eventsAreCached() ? 'event:cache' : null])),
                'config_cached' => app()->configurationIsCached()]);
            return ['directory' => $directory];
        });
        $directory = $prepared['directory'];
        // 子进程自己持有全局执行锁并更新任务记录；它不加载当前 vendor。
        // 不使用会在析构时终止子进程的 Process；父请求退出后独立 CLI 仍持有锁并记录结果。
        $pipes = [];
        $process = proc_open([config('ptadmin-updates.php_binary'), $directory.'/recover.php', 'run'],
            [0 => ['pipe', 'r'], 1 => ['file', $directory.'/runner.log', 'a'], 2 => ['file', $directory.'/runner.log', 'a']], $pipes, base_path());
        if (!is_resource($process)) {
            throw new UpdateException('无法启动独立 Core 更新进程。', 'update_failed');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $result = is_file($directory.'/result.json') ? UpdateFiles::read($directory.'/result.json') : null;
        if ($exit !== 0 || ($result['status'] ?? null) !== 'succeeded') {
            throw new UpdateException($result['data']['message'] ?? 'Core 更新进程中断，请使用 '.$directory.'/recover.php 恢复并检查任务记录。', 'update_failed');
        }
        return $result['data'];
    }
}
