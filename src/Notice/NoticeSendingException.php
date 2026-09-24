<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use RuntimeException;

final class NoticeSendingException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
