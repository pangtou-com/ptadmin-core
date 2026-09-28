<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks\Executors;

use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Package\PackageDownloader;
use PTAdmin\Addon\Lifecycle\StateManager;
use PTAdmin\Core\Tasks\TaskExecutorInterface;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;
use RuntimeException;

final class AddonTaskExecutor implements TaskExecutorInterface
{
    public function __construct(private Installer $installer, private PackageDownloader $downloader, private TaskStore $store, private StateManager $states)
    {
    }

    /** @return array<string, mixed> */
    public function execute(array $task, TaskManager $tasks): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $type = (string) ($task['type'] ?? '');
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $webPrefix = (string) ($payload['web_prefix'] ?? config('ptadmin.web_prefix', 'admin'));
        $tasks->heartbeat($taskId, 'validating', 5, 'Validating addon package.');
        $onProgress = static function (string $stage, int $progress, string $message) use ($tasks, $taskId): void {
            $tasks->heartbeat($taskId, $stage, $progress, $message);
        };
        if ($type === TaskManager::ADDON_DOWNLOAD) {
            $tasks->heartbeat($taskId, 'download', 10, '正在下载插件包。');
            $artifactRoot = $this->store->artifactsPath($taskId);
            $source = (string) ($payload['url'] ?? $payload['source'] ?? '');
            $filename = (string) ($payload['filename'] ?? basename((string) parse_url($source, PHP_URL_PATH)) ?: 'addon.zip');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $filename) !== 1) {
                throw new RuntimeException('The addon artifact filename is invalid.');
            }
            $result = $this->downloader->download($source, $artifactRoot.'/'.$filename, isset($payload['sha256']) ? (string) $payload['sha256'] : null);
        } elseif ($type === TaskManager::ADDON_FRONTEND_SYNC) {
            $tasks->heartbeat($taskId, 'publishing', 40, '正在同步插件前端资源。');
            $result = $this->installer->syncFrontend((string) ($payload['code'] ?? ''), $webPrefix);
        } elseif ($type === TaskManager::ADDON_DISABLE) {
            $tasks->heartbeat($taskId, 'activating', 40, '正在停用插件。');
            $result = $this->states->disable((string) ($payload['code'] ?? ''));
        } elseif ($type === TaskManager::ADDON_ENABLE) {
            $tasks->heartbeat($taskId, 'activating', 40, '正在启用插件。');
            $result = $this->states->enable((string) ($payload['code'] ?? ''));
        } elseif ($type === TaskManager::ADDON_UNINSTALL) {
            $tasks->heartbeat($taskId, 'removing', 40, '正在卸载插件。');
            $result = $this->installer->uninstall((string) ($payload['code'] ?? ''), $webPrefix);
        } elseif ($type === TaskManager::ADDON_UPDATE) {
            $result = $this->installer->install(
                (string) ($payload['source'] ?? ''),
                $webPrefix,
                true,
                isset($payload['code']) ? (string) $payload['code'] : null,
                isset($payload['sha256']) ? (string) $payload['sha256'] : null,
                $onProgress,
            );
        } else {
            $result = $this->installer->install(
                (string) ($payload['source'] ?? ''),
                $webPrefix,
                false,
                isset($payload['code']) ? (string) $payload['code'] : null,
                isset($payload['sha256']) ? (string) $payload['sha256'] : null,
                $onProgress,
            );
        }
        $tasks->heartbeat($taskId, 'activated', 100, 'Addon operation completed.', $result);
        return $result;
    }
}
