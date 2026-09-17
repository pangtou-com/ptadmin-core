<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use RuntimeException;

final class TaskException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public string $errorCode = 'task_error',
        public array $details = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
