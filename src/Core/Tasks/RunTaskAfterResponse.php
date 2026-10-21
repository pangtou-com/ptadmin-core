<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Illuminate\Support\Facades\Artisan;

/** 在 HTTP 响应完成后执行文件任务，避免把安装或发布工作阻塞在接口响应中。 */
final class RunTaskAfterResponse
{
    public function __construct(public readonly string $taskId)
    {
    }

    public function handle(): void
    {
        // 任务命令的输出不能混入仍在写出的 JSON 响应或宿主日志缓冲区。
        $outputLevel = ob_get_level();
        ob_start();
        try {
            Artisan::call('ptadmin:task:run', ['task_id' => $this->taskId]);
        } finally {
            while (ob_get_level() > $outputLevel) {
                ob_end_clean();
            }
        }
    }
}
