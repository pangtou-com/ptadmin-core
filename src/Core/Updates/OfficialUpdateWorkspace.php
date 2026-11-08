<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

final class OfficialUpdateWorkspace
{
    public function __construct(private readonly \PTAdmin\Core\Tasks\TaskStore $tasks)
    {
    }

    public function run(string $taskId, callable $operation): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $taskId)) {
            throw new UpdateException('更新任务编号无效。', 'invalid_task');
        }
        $root = config('ptadmin-updates.path').'/operations';
        UpdateFiles::directory($root);
        $lock = fopen($root.'/.execution.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new UpdateException('已有更新或恢复进程正在执行。', 'update_running');
        }
        try {
            $directory = $root.'/'.$taskId;
            if (is_dir($directory)) {
                throw new UpdateException('该更新已有工作记录，请先恢复或查看结果，不能重复执行。', 'update_exists');
            }
            UpdateFiles::directory($directory);
            UpdateFiles::write($directory.'/operation.json', ['task_directory' => $this->tasks->taskDirectory($taskId)]);
            // 恢复所需代码位于私有工作目录，vendor 被替换或损坏时仍可运行。
            foreach (['UpdateFiles', 'UpdateRecovery', 'CoreUpdateRunner'] as $class) {
                UpdateFiles::copy(__DIR__.'/'.$class.'.php', $directory.'/'.$class.'.php');
            }
            UpdateFiles::copy(__DIR__.'/../../../resources/update-recovery.php', $directory.'/recover.php');
            UpdateFiles::copy(__DIR__.'/../../../resources/update-health.php', $directory.'/health.php');
            return $operation($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
