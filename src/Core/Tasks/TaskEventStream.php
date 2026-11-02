<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Generator;

/** 从已有文件日志推送增量事件；不执行任务、不持有文件锁等待，也不在循环中访问数据库。 */
final class TaskEventStream
{
    public function __construct(private readonly TaskManager $tasks)
    {
    }

    /** @return Generator<int, string> */
    public function frames(string $taskId, int $cursor = 0, float $duration = 20, int $intervalMicroseconds = 150000): Generator
    {
        $deadline = microtime(true) + $duration;
        $heartbeat = microtime(true);
        $previous = null;
        yield ": connected\n\n";
        do {
            $snapshot = $this->tasks->get($taskId);
            $terminal = in_array($snapshot['status'], ['succeeded', 'failed', 'blocked', 'cancelled'], true);
            do {
                $page = $this->tasks->events($taskId, $cursor, 500);
                foreach ($page['events'] as $event) {
                    yield $this->frame('log', $event, $event['cursor'] + 1);
                }
                $cursor = $page['next_cursor'];
            } while ($page['has_more']);
            // 终态先写状态，后写日志和结果。必须等三者齐全，不能靠固定延时猜测落盘结束。
            $tail = $terminal ? ($this->tasks->events($taskId, max(0, $cursor - 1), 1)['events'][0] ?? null) : null;
            $complete = $terminal && ($tail['status'] ?? null) === $snapshot['status']
                && ($snapshot['status'] === 'cancelled' || ($snapshot['result']['status'] ?? null) === $snapshot['status']);
            if ((!$terminal || $complete) && $snapshot !== $previous) {
                yield $this->frame('snapshot', $snapshot);
                $previous = $snapshot;
            }
            if ($complete) {
                yield $this->frame('complete', ['cursor' => $cursor], $cursor);
                return;
            }
            if (microtime(true) - $heartbeat >= 5) {
                yield ": heartbeat\n\n";
                $heartbeat = microtime(true);
            }
            usleep($intervalMicroseconds);
        } while (microtime(true) < $deadline);
        // 定期释放 PHP worker；客户端立即按游标续接，并重新经过认证和授权。
        yield $this->frame('reconnect', ['cursor' => $cursor], $cursor);
    }

    /** @param array<string, mixed> $data */
    private function frame(string $event, array $data, ?int $cursor = null): string
    {
        return ($cursor === null ? '' : 'id: '.$cursor."\n")
            .'event: '.$event."\n"
            .'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }
}
