<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Core\Tasks\TaskManager;

final class OfficialShellUpdater
{
    public function __construct(
        private readonly OfficialUpdateInstaller $installer,
        private readonly OfficialShellArchive $archives,
        private readonly OfficialUpdateWorkspace $workspace,
        private readonly InstalledVersions $installed,
        private readonly \PTAdmin\Core\Tasks\TaskStore $store,
    ) {
    }

    public function execute(array $task, TaskManager $tasks): array
    {
        return $this->workspace->run($task['task_id'], function (string $directory) use ($task, $tasks): array {
            $id = $task['task_id'];
            $version = $task['payload']['version'];
            $tasks->heartbeat($id, 'checking', 5, '正在复核官方版本和当前环境。');
            $release = $this->installer->resolve('admin-shell', $version);
            $lastHeartbeat = 0;
            $archive = $this->archives->download($release['packages']['build'], $directory, function (int $bytes) use ($tasks, $id, &$lastHeartbeat): void {
                if (time() - $lastHeartbeat >= 5) {
                    $tasks->heartbeat($id, 'downloading', 20, '正在下载 Shell 构建包。', ['bytes' => $bytes]);
                    $lastHeartbeat = time();
                }
            });
            $tasks->heartbeat($id, 'verifying', 40, '正在校验构建包和清单。');
            $this->archives->extract($archive, $directory.'/bundle');
            $versions = $this->installed->read();
            $verifier = new AdminFrontendManifest($versions['core']['version'], $versions['api_protocol'], $versions['schema_protocol']);
            $manifest = $verifier->verify($directory.'/bundle');
            if ($manifest['frontend_version'] !== $version || $manifest['artifact_sha256'] !== $release['artifact_sha256']) {
                throw new UpdateException('构建包的版本或资源摘要与官方记录不一致。', 'invalid_archive');
            }
            $allowed = array_fill_keys([...array_column($manifest['files'], 'path'), 'manifest.json'], true);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory.'/bundle', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = substr($file->getPathname(), strlen($directory.'/bundle/'));
                if (!isset($allowed[$relative])) {
                    throw new UpdateException('构建包包含清单之外的文件。', 'invalid_archive');
                }
            }
            $target = public_path(config('ptadmin.web_prefix'));
            $previous = $verifier->verify($target);
            if (stat($target)['dev'] !== stat($directory)['dev']) {
                throw new UpdateException('后台目录与更新备份不在同一文件系统，请使用部署流程更新。', 'update_unavailable');
            }
            $tasks->heartbeat($id, 'preparing', 55, '正在准备新目录并保留模块、插件和用户文件。');
            $sourceSnapshot = PackageManifest::digest(PackageManifest::files(dirname($target), [basename($target)]));
            UpdateFiles::copy($target, $directory.'/staged');
            $oldPaths = array_fill_keys([...array_column($previous['files'], 'path'), 'manifest.json'], true);
            foreach ($oldPaths as $path => $_) {
                if (!unlink($directory.'/staged/'.$path)) {
                    throw new UpdateException('无法准备旧受管文件替换。', 'prepare_failed');
                }
            }
            foreach ($allowed as $path => $_) {
                if (file_exists($directory.'/staged/'.$path)) {
                    throw new UpdateException('新 Shell 文件与用户自有文件冲突：'.$path, 'user_file_conflict');
                }
                UpdateFiles::copyPublicAsset($directory.'/bundle/'.$path, $directory.'/staged/'.$path);
            }
            $verifier->verify($directory.'/staged');
            $latest = $this->installer->resolve('admin-shell', $version);
            if ($latest['packages']['build'] !== $release['packages']['build']) {
                throw new UpdateException('准备期间发行包记录变化，请重新提交更新。', 'release_changed');
            }
            $tasks->heartbeat($id, 'replacing', 75, '正在备份并切换 Shell。中断恢复入口：php '.escapeshellarg($directory.'/recover.php').' restore');
            $this->promote($directory, $target, $sourceSnapshot, $release, $verifier, $task, $tasks);
            return ['component' => 'admin-shell', 'version' => $version, 'reload_required' => true,
                'message' => 'Shell 已更新，请刷新后台。'];
        });
    }

    private function promote(string $directory, string $target, string $sourceSnapshot, array $release,
        AdminFrontendManifest $verifier, array $task, TaskManager $tasks): void
    {
        $framework = storage_path('framework');
        UpdateFiles::directory($framework);
        $maintenance = [$framework.'/maintenance.php', $framework.'/down'];
        foreach ($maintenance as $path) {
            if (file_exists($path)) {
                throw new UpdateException('应用已进入其他维护流程，停止更新。', 'update_unavailable');
            }
        }
        $recovery = new UpdateRecovery($directory);
        $lock = null;
        $metadata = ['component' => 'admin-shell', 'version' => $release['version'],
            'maintenance_files' => $maintenance, 'task_directory' => $this->store->taskDirectory($task['task_id'])];
        // 恢复脚本也必须取得应用执行锁，不能在仍有业务写入时恢复公开目录。
        UpdateFiles::write($directory.'/plan.json', ['storage' => storage_path()]);
        UpdateFiles::write($directory.'/journal.json', $metadata + ['status' => 'entering_maintenance', 'database_started' => false, 'entries' => []]);
        try {
            if (file_put_contents($maintenance[0], '<?php http_response_code(503); header("Retry-After: 5"); header("X-PTAdmin-Update: maintenance"); echo "系统正在更新，请稍后刷新。"; exit;') === false) {
                throw new UpdateException('无法进入维护状态。', 'update_failed');
            }
            UpdateFiles::write($maintenance[1], ['time' => time(), 'retry' => 5]);
            $lock = fopen($framework.'/ptadmin-runtime.lock', 'c+');
            if ($lock === false) {
                throw new UpdateException('无法打开应用执行锁。', 'update_failed');
            }
            $deadline = microtime(true) + 60;
            $lastHeartbeat = 0;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new UpdateException('旧请求或常驻命令尚未退出，请暂停队列、调度和长任务后重试。', 'update_running');
                }
                if (time() - $lastHeartbeat >= 5) {
                    $tasks->heartbeat($task['task_id'], 'draining', 75, '等待已进入的请求和命令完成，再切换后台界面。');
                    $lastHeartbeat = time();
                }
                usleep(100000);
            }
            $matches = array_values(array_filter(app(OfficialUpdateService::class)->fetchCatalog()['results'], static fn (array $entry): bool =>
                $entry['component'] === 'admin-shell' && $entry['version'] === $release['version']));
            if (count($matches) !== 1 || $matches[0]['packages']['build'] !== $release['packages']['build']) {
                throw new UpdateException('切换前 Shell 发行已撤回或变化，请重新检查。', 'release_changed');
            }
            if (PackageManifest::digest(PackageManifest::files(dirname($target), [basename($target)])) !== $sourceSnapshot) {
                throw new UpdateException('准备期间后台界面或用户文件发生变化，已停止切换，请重新发起更新。', 'source_changed');
            }
            $recovery->switch([['source' => $directory.'/staged', 'target' => $target]], $metadata);
            $verifier->verify($target);
            foreach ($maintenance as $path) {
                if (!unlink($path)) {
                    throw new UpdateException('后台界面已切换，但无法退出维护状态。', 'update_failed');
                }
            }
            $recovery->stage('completed');
        } catch (\Throwable $error) {
            $recovery->restore();
            throw $error;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

}
