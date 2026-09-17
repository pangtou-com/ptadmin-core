<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks\Executors;

use PTAdmin\Core\Install\AdminFrontendPublisher;
use PTAdmin\Core\Tasks\TaskExecutorInterface;
use PTAdmin\Core\Tasks\TaskManager;

final class AdminFrontendUpdateExecutor implements TaskExecutorInterface
{
    public function __construct(private AdminFrontendPublisher $publisher)
    {
    }

    /** @return array<string, mixed> */
    public function execute(array $task, TaskManager $tasks): array
    {
        $taskId = (string) $task['task_id'];
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $prefix = (string) ($payload['web_prefix'] ?? config('ptadmin.web_prefix', 'admin'));
        $source = isset($payload['source']) && is_string($payload['source']) ? $payload['source'] : null;
        $tasks->heartbeat($taskId, 'publishing', 10, 'Validating frontend bundle.');
        $result = $this->publisher->publishForUpdate($prefix, $source);
        $tasks->heartbeat($taskId, 'published', 100, 'Frontend bundle published.');
        return $result;
    }
}
