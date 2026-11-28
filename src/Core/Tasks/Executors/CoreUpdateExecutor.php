<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks\Executors;

use PTAdmin\Core\Tasks\{TaskExecutorInterface, TaskManager, TaskStore};
use PTAdmin\Core\Updates\{OfficialUpdateInstaller, OfficialUpdateWorkspace, UpdateException, UpdateFiles, UpdateRuntimeProbe, DependencyBaseline, ApplicationUpdatePlan, ApplicationPackage, PackageManifest, ComposerProjectMetadata, OfficialShellArchive};

final class CoreUpdateExecutor implements TaskExecutorInterface
{
    public function __construct(
        private readonly OfficialUpdateInstaller $installer,
        private readonly OfficialUpdateWorkspace $workspace,
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
            $baseline = app(DependencyBaseline::class)->inspect(null, true);
            $selection = app(ApplicationUpdatePlan::class)->select($release, $baseline);
            $composerInstallation = ($baseline['installation'] ?? '') === 'composer';
            $inputs = [];
            foreach (['composer.json', 'composer.lock', $composerInstallation ? DependencyBaseline::COMPOSER_IDENTITY : PackageManifest::IDENTITY] as $file) {
                $inputs[$file] = hash_file('sha256', base_path($file));
            }
            $sourceBytes = $composerInstallation ? array_sum(array_column($baseline['source_snapshot'], 'size')) : $baseline['target']['managed_bytes'];
            $requiredBytes = 4 * $selection['target']['managed_bytes'] + $sourceBytes + $selection['artifact']['size'] + 67108864;
            $availableBytes = disk_free_space($directory);
            if ($availableBytes === false || $availableBytes < $requiredBytes) {
                throw new UpdateException('磁盘空间不足，准备和备份至少需要 '.ceil($requiredBytes / 1048576).' MiB 可用空间。', 'insufficient_space');
            }
            if (stat(base_path('vendor'))['dev'] !== stat($directory)['dev']) {
                throw new UpdateException('依赖和备份目录必须在同一文件系统。', 'update_unavailable');
            }
            $php = config('ptadmin-updates.php_binary');
            app(UpdateRuntimeProbe::class)->assertUsable($php);
            $lastProgress = 0.0;
            $archive = app(OfficialShellArchive::class)->download($selection['artifact'], $directory,
                static function (int $bytes) use ($tasks, $task, &$lastProgress): void {
                    if (microtime(true) - $lastProgress >= 1) {
                        $tasks->heartbeat($task['task_id'], 'downloading', 20, '正在下载官方升级包。', ['bytes' => $bytes]);
                        $lastProgress = microtime(true);
                    }
                });
            $packages = new ApplicationPackage();
            $manifest = $packages->extract($archive, $directory.'/bundle', config('ptadmin-updates.max_uncompressed_bytes'));
            if ($manifest['kind'] !== $selection['artifact']['kind'] || $manifest['target']['build_id'] !== $selection['target']['build_id']) {
                throw new UpdateException('升级包与选定发行物不一致。', 'invalid_archive');
            }
            $target = $packages->prepare($directory.'/bundle', $directory.'/project', base_path());
            ComposerProjectMetadata::preserve(base_path(), $directory.'/project');
            PackageManifest::verify($directory.'/project', $target, false);
            $tasks->heartbeat($task['task_id'], 'preparing_target', 45, '完整目标准备完成，正在准备配套后台界面。');
            $shellPath = 'public/'.config('ptadmin.web_prefix');
            $shellInput = PackageManifest::digest(PackageManifest::files(base_path(), [$shellPath]));
            $shellTarget = app(\PTAdmin\Core\Updates\ApplicationShellStage::class)->prepare($directory, $target);
            UpdateFiles::write($directory.'/plan.json', ['root' => base_path(), 'storage' => storage_path(), 'version' => $release['version'],
                'git_commit' => $release['git_commit'], 'php' => $php, 'inputs' => $inputs, 'shell_target' => $shellTarget, 'artifact' => $selection['artifact'],
                'shell_input' => ['path' => $shellPath, 'sha256' => $shellInput],
                'composer_source' => $composerInstallation ? ['target' => $baseline['target'], 'files' => $baseline['source_snapshot'],
                    'identity_sha256' => is_file(base_path(PackageManifest::IDENTITY)) ? hash_file('sha256', base_path(PackageManifest::IDENTITY)) : null] : null,
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
