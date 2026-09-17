<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Illuminate\Contracts\Events\Dispatcher;

interface AddonHookInterface
{
    /** Register event listeners for an active addon. */
    public function register(Dispatcher $events, string $code, array $manifest): void;
}
