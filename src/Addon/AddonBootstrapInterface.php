<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Illuminate\Contracts\Foundation\Application;

interface AddonBootstrapInterface
{
    /** Register services, hooks and runtime integrations for an active addon. */
    public function register(Application $app, string $code, array $manifest): void;
}
