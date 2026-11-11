<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PTAdmin\Core\Tasks\AddonTaskProcess;
use PTAdmin\Core\Tasks\TaskStore;

// 仅供 Core 启动的 CLI 子进程使用；不能通过公开 HTTP 入口执行。
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    exit(1);
}
$basePath = $argv[1];
$taskId = $argv[2];
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($taskId): void {
    $store = new TaskStore((string) $app['config']->get('ptadmin.tasks.path', $app->storagePath('app/ptadmin/tasks')));
    $state = $store->readState($taskId);
    if ($state === null || $state['status'] !== 'running') {
        throw new RuntimeException('插件子进程只能执行已经启动的任务。');
    }
    $code = AddonTaskProcess::target($state + ['payload' => $store->readPayload($taskId) ?? []]);
    if ($code === null) {
        throw new RuntimeException('当前任务不属于插件包任务。');
    }
    $app->instance(AddonTaskProcess::class, new AddonTaskProcess($taskId, $code));
});
$kernel = $app->make(Kernel::class);
try {
    $kernel->bootstrap();
    $status = $kernel->call('ptadmin:task:run', ['task_id' => $taskId]);
    $kernel->terminate(new \Symfony\Component\Console\Input\ArrayInput([]), $status);
    exit($status);
} catch (Throwable $exception) {
    // 启动阶段尚未进入任务命令，也必须保留诊断并将 running 任务结束为失败。
    try {
        $tasks = $app->make(\PTAdmin\Core\Tasks\TaskManager::class);
        if ($tasks->get($taskId)['status'] === 'running') {
            $code = $exception instanceof \PTAdmin\Addon\Exception\AddonException
                ? $exception->errorCode : 'addon_process_boot_failed';
            $tasks->fail($taskId, ['code' => $code, 'message' => $exception->getMessage()]);
        }
    } catch (Throwable) {
        // 宿主无法解析任务服务时，由父进程记录独立进程失败。
    }
    $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($exception);
    exit(1);
}
