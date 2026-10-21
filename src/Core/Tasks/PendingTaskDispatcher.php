<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Illuminate\Support\Facades\Bus;
use PTAdmin\Cloud\ExecuteCloudPackageTask;

/** HTTP 创建、查询恢复和重试共用调度范围；模块自有任务继续由模块调度。 */
final class PendingTaskDispatcher
{
    private const LOCAL_TYPES = [
        TaskManager::ADMIN_FRONTEND_UPDATE,
        TaskManager::ADDON_DOWNLOAD,
        TaskManager::ADDON_INSTALL,
        TaskManager::ADDON_UPDATE,
        TaskManager::ADDON_UNINSTALL,
        TaskManager::ADDON_FRONTEND_SYNC,
        TaskManager::ADDON_ENABLE,
        TaskManager::ADDON_DISABLE,
        TaskManager::SITE_THEME_INSTALL,
        TaskManager::SITE_THEME_REMOVE,
    ];

    /** @param array<string,mixed> $task 已通过当前接口授权的任务快照 */
    public function dispatch(array $task): void
    {
        if ($task['status'] !== 'pending') {
            return;
        }
        if ($task['type'] === TaskManager::CLOUD_PACKAGE) {
            Bus::dispatchAfterResponse(new ExecuteCloudPackageTask($task['task_id']));
        } elseif (in_array($task['type'], self::LOCAL_TYPES, true)) {
            Bus::dispatchAfterResponse(new RunTaskAfterResponse($task['task_id']));
        }
    }
}
