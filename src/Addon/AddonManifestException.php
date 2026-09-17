<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonManifestException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public string $errorCode = 'invalid_addon_manifest', public array $details = [])
    {
        parent::__construct($message);
    }
}
