<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

interface AddonResourceSynchronizerInterface
{
    /** @return array{schemas:int,configs:int,admin_resources:int} */
    public function sync(string $directory, string $code): array;

    public function deactivate(string $code): void;
}
