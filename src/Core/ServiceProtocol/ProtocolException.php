<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

final class ProtocolException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUncertain = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
