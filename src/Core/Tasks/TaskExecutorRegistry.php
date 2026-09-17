<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use RuntimeException;

final class TaskExecutorRegistry
{
    /** @var array<string, TaskExecutorInterface> */
    private array $executors = [];

    public function register(string $type, TaskExecutorInterface $executor): void
    {
        $this->executors[$type] = $executor;
    }

    public function has(string $type): bool
    {
        return isset($this->executors[$type]);
    }

    /** @return array<string, mixed> */
    public function execute(array $task, TaskManager $tasks): array
    {
        $type = (string) ($task['type'] ?? '');
        if (!isset($this->executors[$type])) {
            throw new RuntimeException('No executor is registered for task type: '.$type);
        }
        return $this->executors[$type]->execute($task, $tasks);
    }
}
