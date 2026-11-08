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
                $parent = dirname($directory.'/staged/'.$path);
                if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
                    throw new UpdateException('无法创建 Shell 资源目录。', 'prepare_failed');
                }
                UpdateFiles::copy($directory.'/bundle/'.$path, $directory.'/staged/'.$path);
                chmod($directory.'/staged/'.$path, 0644);
            }
            $verifier->verify($directory.'/staged');
            $latest = $this->installer->resolve('admin-shell', $version);
            if ($latest['packages']['build'] !== $release['packages']['build']) {
                throw new UpdateException('准备期间发行包记录变化，请重新提交更新。', 'release_changed');
            }
            $tasks->heartbeat($id, 'replacing', 75, '正在备份并切换 Shell。中断恢复入口：php '.escapeshellarg($directory.'/recover.php').' restore');
            $recovery = new UpdateRecovery($directory);
            try {
                $recovery->switch([['source' => $directory.'/staged', 'target' => $target]], ['component' => 'admin-shell', 'version' => $version, 'task_directory' => $this->store->taskDirectory($id)]);
                $verifier->verify($target);
                $recovery->stage('completed');
            } catch (\Throwable $error) {
                if (is_file($directory.'/journal.json')) {
                    $recovery->restore();
                }
                throw $error;
            }
            return ['component' => 'admin-shell', 'version' => $version, 'reload_required' => true,
                'message' => 'Shell 已更新，请刷新后台。'];
        });
    }
}
