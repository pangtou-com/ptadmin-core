<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskExecutorRegistry;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Core\Theme\ThemeException;

final class TaskRunCommand extends Command
{
    protected $signature = 'ptadmin:task:run {task_id}';
    protected $description = 'Execute a PTAdmin background task.';

    public function handle(TaskManager $tasks, TaskExecutorRegistry $executors): int
    {
        $taskId = (string) $this->argument('task_id');
        $started = false;
        try {
            $task = $tasks->get($taskId);
            if (in_array($task['status'] ?? null, ['succeeded', 'cancelled'], true)) {
                $this->line('Task already finished.');
                return self::SUCCESS;
            }
            if (($task['status'] ?? null) === 'running') {
                $this->error('Task is already running.');
                return self::FAILURE;
            }
            $tasks->start($taskId, 'starting');
            $started = true;
            if (!$executors->has((string) $task['type'])) {
                $tasks->block($taskId, ['code' => 'executor_unavailable', 'message' => 'Task executor is not installed yet.']);
                $this->error('No executor is registered for this task type.');
                return self::FAILURE;
            }
            $result = $executors->execute($tasks->getForExecution($taskId), $tasks);
            $tasks->succeed($taskId, $result);
            $this->info('Task completed.');
            return self::SUCCESS;
        } catch (TaskException $exception) {
            try {
                if ($started) {
                    $tasks->fail($taskId, ['code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                }
            } catch (TaskException) {
                // A missing task cannot be transitioned; preserve the command error.
            }
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            try {
                $code = $exception instanceof AddonException || $exception instanceof ThemeException ? $exception->errorCode : 'executor_failed';
                $tasks->fail($taskId, ['code' => $code, 'message' => $exception->getMessage()]);
            } catch (TaskException) {
            }
            $this->error('Task execution failed.');
            return self::FAILURE;
        }
    }
}
