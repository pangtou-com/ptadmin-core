<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

interface CloudSessionStoreInterface
{
    /** @return array<string, mixed>|null */
    public function get(): ?array;

    /** @param array<string, mixed> $session */
    public function put(array $session): void;

    public function forget(): void;
}
