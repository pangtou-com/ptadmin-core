<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

final class UpdateException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
