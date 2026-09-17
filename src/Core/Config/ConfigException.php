<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use RuntimeException;

final class ConfigException extends RuntimeException
{
    public string $errorCode;
    /** @var array<string, mixed> */
    public array $details;

    /** @param array<string, mixed> $details */
    public function __construct(string $message, string $errorCode = 'config_invalid', array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->details = $details;
    }
}
