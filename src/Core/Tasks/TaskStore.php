<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Closure;
use RuntimeException;

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
        $this->initialize($taskId);
        $this->writeJson($this->taskDirectory($taskId).'/state.json', $state);
    }

    /** @return array<string, mixed>|null */
    public function readState(string $taskId): ?array
    {
        return $this->readJson($this->taskDirectory($taskId).'/state.json');
    }

    /** @param array<string, mixed> $payload */
    public function writePayload(string $taskId, array $payload): void
    {
        $this->initialize($taskId);
        $this->writeJson($this->taskDirectory($taskId).'/payload.json', $payload);
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
