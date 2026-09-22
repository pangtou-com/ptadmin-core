<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use RuntimeException;

final class ThemeException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public string $errorCode = 'invalid_theme',
        public array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
