<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use RuntimeException;

final class CloudException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'cloud_error',
        public readonly array $details = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
