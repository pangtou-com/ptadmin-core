<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use RuntimeException;

final class NoticeDefinitionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'notice_definition_invalid')
    {
        parent::__construct($message);
    }
}
