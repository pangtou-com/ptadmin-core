<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use RuntimeException;

final class DictionaryException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
