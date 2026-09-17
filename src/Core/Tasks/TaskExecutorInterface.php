<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

interface TaskExecutorInterface
{
    /** @return array<string, mixed> */
    public function execute(array $task, TaskManager $tasks): array;
}
