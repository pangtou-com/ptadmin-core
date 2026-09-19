<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use LogicException;

final class RuntimeDiagnostic extends LogicException
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
