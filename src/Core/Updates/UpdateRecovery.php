<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;

/** 先持久化切换意图，再移动文件；此类不依赖 vendor，可由私有恢复脚本直接加载。 */
final class UpdateRecovery
{
    public function __construct(private readonly string $directory)
    {
    }

    public function switch(array $entries, array $metadata = []): void
    {
        $journal = $metadata + ['status' => 'switching', 'database_started' => false, 'entries' => []];
        foreach ($entries as $index => $entry) {
            if (!file_exists($entry['target']) || is_link($entry['target']) || !file_exists($entry['source'])) {
                throw new RuntimeException('待切换文件缺失或为符号链接。');
            }
            $journal['entries'][] = $entry + ['backup' => $this->directory.'/backup/'.$index, 'restored' => false];
        }
        UpdateFiles::directory($this->directory.'/backup');
        $this->save($journal);
        foreach ($journal['entries'] as $entry) {
            UpdateFiles::move($entry['target'], $entry['backup']);
            UpdateFiles::move($entry['source'], $entry['target']);
        }
        $this->stage('switched');
    }

    public function stage(string $status, array $changes = []): void
    {
        $this->save(array_replace($this->read(), $changes, ['status' => $status]));
    }

    public function restore(bool $databaseRestored = false, bool $recordTask = false): array
    {
        // 准备阶段中断尚无切换日志；写前日志约束保证此时运行目录未被修改。
        $journal = is_file($this->directory.'/journal.json') ? $this->read()
            : UpdateFiles::read($this->directory.'/operation.json') + ['status' => 'preparing', 'database_started' => false, 'entries' => []];
        if ($journal['status'] === 'restored') {
            return $journal;
        }
        if ($journal['status'] === 'completed') {
            throw new RuntimeException('该更新已完成。为避免覆盖后续用户资产，请通过部署流程回退；恢复工具只处理失败或中断的更新。');
        }
        if (($journal['database_started'] ?? false) && !$databaseRestored) {
            throw new RuntimeException('数据库迁移或资源同步已经开始。请先按备份恢复数据库，再使用 --database-restored 恢复文件；当前维护状态保留。');
        }
        $journal['status'] = 'restoring';
        $this->save($journal);
        foreach (array_reverse(array_keys($journal['entries'])) as $index) {
            $entry = $journal['entries'][$index];
            if ($entry['restored']) {
                continue;
            }
            if (file_exists($entry['backup'])) {
                if (file_exists($entry['target'])) {
                    UpdateFiles::move($entry['target'], $this->directory.'/failed-'.$index.'-'.bin2hex(random_bytes(4)));
                }
                UpdateFiles::move($entry['backup'], $entry['target']);
            }
            if (!file_exists($entry['target'])) {
                throw new RuntimeException('恢复文件和备份均不存在，停止恢复并保留记录。');
            }
            $journal['entries'][$index]['restored'] = true;
            $this->save($journal);
        }
        // 只删除本次更新创建的维护文件，不接触部署负责人原有维护状态。
        foreach ($journal['maintenance_files'] ?? [] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('文件已恢复，但无法退出维护状态。');
            }
        }
        $journal['status'] = 'restored';
        if ($recordTask) {
            $this->recordRestoration($journal);
        }
        $this->save($journal);
        return $journal;
    }

    public function read(): array
    {
        return UpdateFiles::read($this->directory.'/journal.json');
    }

    private function save(array $journal): void
    {
        UpdateFiles::write($this->directory.'/journal.json', $journal);
    }

    /** 即使 Laravel 无法启动，也能结束中断任务；不重建已被清理的历史记录。 */
    private function recordRestoration(array $journal): void
    {
        $task = $journal['task_directory'] ?? null;
        if (!is_string($task) || !is_file($task.'/state.json')) {
            return;
        }
        $stateLock = fopen($task.'/.state.lock', 'c+');
        $indexLock = fopen(dirname($task).'/.index.lock', 'c+');
        if ($stateLock === false || $indexLock === false) {
            throw new RuntimeException('文件已恢复，但无法锁定任务记录。');
        }
        try {
            flock($stateLock, LOCK_EX);
            flock($indexLock, LOCK_EX);
            $state = UpdateFiles::read($task.'/state.json');
            $state['status'] = 'failed';
            $state['stage'] = 'restored';
            $state['message'] = '更新未完成，旧文件已恢复。'.(($journal['database_started'] ?? false) ? '操作人已确认恢复数据库。' : '未执行数据库迁移。');
            $state['error_code'] = 'update_restored';
            $state['error_message'] = $state['message'];
            $state['updated_at'] = $state['finished_at'] = gmdate('c');
            $state['heartbeat_at'] = null;
            file_put_contents(dirname($task).'/.index-dirty', '1');
            UpdateFiles::write($task.'/state.json', $state);
            UpdateFiles::write($task.'/result.json', ['status' => 'failed', 'data' => ['message' => $state['message'], 'recovery_completed' => true], 'completed_at' => gmdate('c')]);
            $event = array_intersect_key($state, array_flip(['task_id', 'type', 'stage', 'status', 'progress', 'message', 'request_id']));
            file_put_contents($task.'/events.jsonl', json_encode($event + ['created_at' => gmdate('c'), 'data' => []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        } finally {
            flock($indexLock, LOCK_UN);
            fclose($indexLock);
            flock($stateLock, LOCK_UN);
            fclose($stateLock);
        }
    }
}
