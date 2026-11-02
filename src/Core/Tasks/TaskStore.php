<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Closure;
use RuntimeException;
use JsonException;

final class TaskStore
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
        $this->ensureDirectory($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @param Closure(): mixed $callback */
    public function withCreationLock(Closure $callback): mixed
    {
        $handle = fopen($this->root.'/.create.lock', 'c+');
        if (false === $handle) {
            throw new RuntimeException('Unable to open task creation lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock task creation.');
            }
            $result = $callback();
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    public function taskDirectory(string $taskId): string
    {
        $this->assertTaskId($taskId);
        return $this->root.'/'.$taskId;
    }

    public function initialize(string $taskId): void
    {
        $directory = $this->taskDirectory($taskId);
        $this->ensureDirectory($directory.'/artifacts');
        if (!is_file($directory.'/events.jsonl')) {
            $this->atomicWrite($directory.'/events.jsonl', '');
        }
    }

    /** @param array<string, mixed> $state */
    public function writeState(string $taskId, array $state): void
    {
        $this->withIndexLock(function (array &$index) use ($taskId, $state): void {
            $this->initialize($taskId);
            $this->writeJson($this->taskDirectory($taskId).'/state.json', $state);
            $index[$taskId] = $this->summary($state, $this->readPayload($taskId) ?? []);
        }, true);
    }

    /** @return array<string, mixed>|null */
    public function readState(string $taskId): ?array
    {
        return $this->readJson($this->taskDirectory($taskId).'/state.json');
    }

    /** @param array<string, mixed> $payload */
    public function writePayload(string $taskId, array $payload): void
    {
        $this->withIndexLock(function (array &$index) use ($taskId, $payload): void {
            $this->initialize($taskId);
            $this->writeJson($this->taskDirectory($taskId).'/payload.json', $payload);
            $state = $this->readState($taskId);
            if ($state !== null) {
                $index[$taskId] = $this->summary($state, $payload);
            }
        }, true);
    }

    /** @return array<string, mixed>|null */
    public function readPayload(string $taskId): ?array
    {
        return $this->readJson($this->taskDirectory($taskId).'/payload.json');
    }

    /** @param array<string, mixed> $result */
    public function writeResult(string $taskId, array $result): void
    {
        $this->initialize($taskId);
        $this->writeJson($this->taskDirectory($taskId).'/result.json', $result);
    }

    /** @return array<string, mixed>|null */
    public function readResult(string $taskId): ?array
    {
        return $this->readJson($this->taskDirectory($taskId).'/result.json');
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        foreach (glob($this->root.'/*/state.json') ?: [] as $path) {
            $state = $this->readJson($path);
            if (is_array($state) && ($state['idempotency_key'] ?? null) === $key) {
                return $state;
            }
        }
        return null;
    }

    public function eventsPath(string $taskId): string
    {
        $this->initialize($taskId);
        return $this->taskDirectory($taskId).'/events.jsonl';
    }

    public function artifactsPath(string $taskId): string
    {
        $this->initialize($taskId);
        return $this->taskDirectory($taskId).'/artifacts';
    }

    /**
     * Update state while holding the task lock. The callback receives the
     * current state and must return the complete replacement state.
     * @param Closure(array<string, mixed>): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    public function updateState(string $taskId, Closure $callback): array
    {
        $this->initialize($taskId);
        $lockPath = $this->taskDirectory($taskId).'/.state.lock';
        $handle = fopen($lockPath, 'c+');
        if (false === $handle) {
            throw new RuntimeException('Unable to open task state lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock task state.');
            }
            $current = $this->readState($taskId) ?? [];
            $next = $callback($current);
            $this->writeState($taskId, $next);
            flock($handle, LOCK_UN);
            return $next;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function summaries(): array
    {
        return $this->withIndexLock(static fn (array &$index): array => $index);
    }

    /**
     * 索引与状态写入共用锁；首次升级或索引损坏时从原始文件重建。
     * @param Closure(array<string, array<string, mixed>>&): mixed $callback
     */
    private function withIndexLock(Closure $callback, bool $mutating = false): mixed
    {
        $handle = fopen($this->root.'/.index.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open task index lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock task index.');
            }
            try {
                $stored = $this->readJson($this->root.'/index.json');
            } catch (JsonException) {
                $stored = null;
            }
            $rebuild = is_file($this->root.'/.index-dirty') || ($stored['version'] ?? null) !== 1 || !is_array($stored['tasks'] ?? null);
            $index = $rebuild ? [] : $stored['tasks'];
            if ($rebuild) {
                foreach (glob($this->root.'/*/state.json') ?: [] as $path) {
                    $taskId = basename(dirname($path));
                    $state = $this->readState($taskId);
                    if ($state !== null) {
                        $index[$taskId] = $this->summary($state, $this->readPayload($taskId) ?? []);
                    }
                }
            }
            $previous = $index;
            if ($mutating) {
                $this->atomicWrite($this->root.'/.index-dirty', '1');
            }
            $result = $callback($index);
            if ($rebuild || $previous !== $index) {
                $this->writeJson($this->root.'/index.json', ['version' => 1, 'tasks' => $index]);
            }
            if (is_file($this->root.'/.index-dirty') && !unlink($this->root.'/.index-dirty')) {
                throw new RuntimeException('Unable to finalize task index.');
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $payload */
    private function summary(array $state, array $payload): array
    {
        $summary = array_intersect_key($state, array_flip([
            'task_id', 'type', 'status', 'stage', 'progress', 'message', 'created_at',
            'updated_at', 'finished_at', 'parent_task_id', 'attempt', 'max_attempts',
        ]));
        $summary['payload'] = TaskRedactor::array(array_intersect_key($payload, array_flip(['name', 'code'])));
        return $summary;
    }

    /**
     * 只删除已确认可清理的任务目录，不跟随符号链接，也不触及任务目录外的文件。
     * 调用方必须持有创建锁并重新校验清理条件。
     * @param list<string> $taskIds
     */
    public function removeHistory(array $taskIds): void
    {
        $staging = $this->root.'/.history-cleanup-'.bin2hex(random_bytes(6));
        $this->ensureDirectory($staging);
        $moved = [];
        try {
            $this->withIndexLock(function (array &$index) use ($taskIds, $staging, &$moved): void {
                foreach ($taskIds as $taskId) {
                    $directory = $this->taskDirectory($taskId);
                    if (!rename($directory, $staging.'/'.$taskId)) {
                        throw new RuntimeException('Unable to stage task history cleanup: '.$taskId);
                    }
                    $moved[] = $taskId;
                    unset($index[$taskId]);
                }
            }, true);
        } catch (\Throwable $exception) {
            foreach ($moved as $taskId) {
                rename($staging.'/'.$taskId, $this->taskDirectory($taskId));
            }
            throw $exception;
        }
        $this->removeDirectory($staging);
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (file_exists($path) || is_link($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException('Unable to remove task history file: '.$path);
                }
            }
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeDirectory($path.'/'.$name);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove task history directory: '.$path);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create task directory: %s', $directory));
        }
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $this->atomicWrite($path, $json);
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (false === file_put_contents($temporary, $contents, LOCK_EX) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Unable to write task file: %s', $path));
        }
        @chmod($path, 0600);
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (false === $contents || trim($contents) === '') {
            return null;
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }

    private function assertTaskId(string $taskId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/', $taskId) !== 1) {
            throw new TaskException('The task id is invalid.', 'invalid_task_id');
        }
    }
}
