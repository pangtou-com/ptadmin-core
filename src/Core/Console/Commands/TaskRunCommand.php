<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\AddonTaskProcess;
use PTAdmin\Core\Tasks\TaskExecutorRegistry;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Core\Theme\ThemeException;

final class TaskRunCommand extends Command
{
    protected $signature = 'ptadmin:task:run {task_id}';
    protected $description = 'Execute a PTAdmin background task.';

    public function handle(TaskManager $tasks, TaskExecutorRegistry $executors, AddonTaskProcess $process): int
    {
        $taskId = (string) $this->argument('task_id');
        $started = false;
        try {
            $task = $tasks->get($taskId);
            if (in_array($task['status'] ?? null, ['succeeded', 'cancelled'], true)) {
                $this->line('Task already finished.');
                return self::SUCCESS;
            }
            if (($task['status'] ?? null) === 'running' && $process->taskId !== $taskId) {
                $this->error('Task is already running.');
                return self::FAILURE;
            }
            if ($process->taskId !== $taskId) {
                $tasks->start($taskId, 'starting');
            }
            $started = true;
            if (!$executors->has((string) $task['type'])) {
                $tasks->block($taskId, ['code' => 'executor_unavailable', 'message' => 'Task executor is not installed yet.']);
                $this->error('No executor is registered for this task type.');
                return self::FAILURE;
            }
            $execution = $tasks->getForExecution($taskId);
            if ($process->taskId !== $taskId && AddonTaskProcess::target($execution) !== null) {
                $process->run($taskId);
                if ($tasks->get($taskId)['status'] !== 'succeeded') {
                    throw new TaskException('插件子进程未完成任务。', 'addon_process_incomplete');
                }
                $this->info('Task completed.');
                return self::SUCCESS;
            }
            $result = $executors->execute($execution, $tasks);
            if ($tasks->get($taskId)['status'] === 'running') {
                $tasks->succeed($taskId, $result);
            }
            $this->info('Task completed.');
            return self::SUCCESS;
        } catch (TaskException $exception) {
            try {
                if ($started && $tasks->get($taskId)['status'] === 'running') {
                    $tasks->fail($taskId, ['code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                }
            } catch (TaskException) {
                // A missing task cannot be transitioned; preserve the command error.
            }
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            try {
                $code = $exception instanceof AddonException || $exception instanceof ThemeException || $exception instanceof \PTAdmin\Cloud\CloudException ? $exception->errorCode
                    : ($exception instanceof \PTAdmin\Schema\Runtime\RuntimeDiagnostic ? $exception->diagnosticCode : 'executor_failed');
                if ($tasks->get($taskId)['status'] === 'running') {
                    $tasks->fail($taskId, ['code' => $code, 'message' => $exception->getMessage()]);
                }
            } catch (TaskException) {
            }
            $this->error('Task execution failed.');
            return self::FAILURE;
        }
    }
}
