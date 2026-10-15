<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Support\Facades\Artisan;

/** HTTP 响应结束后执行已有文件任务，不依赖额外任务表或队列守护进程。 */
final class ExecuteCloudPackageTask
{
    public function __construct(public readonly string $taskId)
    {
    }

    public function handle(): void
    {
        Artisan::call('ptadmin:task:run', ['task_id' => $this->taskId]);
    }
}
