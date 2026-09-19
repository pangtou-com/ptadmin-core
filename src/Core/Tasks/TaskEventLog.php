<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use RuntimeException;

final class TaskEventLog
{
    private TaskStore $store;

    public function __construct(TaskStore $store)
    {
        $this->store = $store;
    }
    
    /**
     * @param string $taskId
     * @param string $type
     * @param string $stage
     * @param string $status
     * @param int|float $progress
     * @param string $message
     * @param array<string, mixed> $data
     * @param string|null $requestId
     * @return array
     * @throws \JsonException
     */
    public function append(
        string $taskId,
        string $type,
        string $stage,
        string $status,
        int|float $progress,
        string $message = '',
        array $data = [],
        ?string $requestId = null
    ): array {
        $event = [
            'task_id' => $taskId,
            'type' => $type,
            'stage' => $stage,
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'message' => TaskRedactor::message($message),
            'data' => TaskRedactor::array($data),
            'created_at' => gmdate('c'),
            'request_id' => $requestId,
        ];
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $handle = fopen($this->store->eventsPath($taskId), 'ab');
        if (false === $handle) {
            throw new RuntimeException('Unable to open task event log.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock task event log.');
            }
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        return $event;
    }

    /**
     * Cursor is a zero-based line offset. Invalid or truncated lines are skipped
     * but never exposed to callers.
     * @return array{events: array<int, array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function read(string $taskId, int $cursor = 0, int $limit = 100): array
    {
        $cursor = max(0, $cursor);
        $limit = max(1, min(500, $limit));
        $handle = fopen($this->store->eventsPath($taskId), 'rb');
        if (false === $handle) {
            throw new RuntimeException('Unable to open task event log.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Unable to lock task event log.');
            }
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $lines = false === $contents || $contents === ''
            ? []
            : preg_split('/\r?\n/', $contents, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($lines)) {
            $lines = [];
        }
        $events = [];
        foreach (array_slice($lines, $cursor, $limit, true) as $lineCursor => $line) {
            try {
                $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($value)) {
                    $value['cursor'] = (int) $lineCursor;
                    $events[] = $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $next = $cursor + count(array_slice($lines, $cursor, $limit));
        return ['events' => $events, 'next_cursor' => $next, 'has_more' => $next < count($lines)];
    }

}
