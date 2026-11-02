<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

interface DirectiveDocumentationGeneratorInterface
{
    /** @return array<string,mixed>|null */
    public function generate(string $directory, string $code, string $version): ?array;
}
