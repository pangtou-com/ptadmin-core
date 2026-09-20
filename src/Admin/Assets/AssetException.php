<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Assets;

final class AssetException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
