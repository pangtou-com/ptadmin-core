<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Runtime\RuntimeApplicationRegistrar;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

final class RuntimeApplicationRegistrarTest extends TestCase
{
    public function testApplicationRegistrationUsesTheFixedHostOwner(): void
    {
        $registry = new RuntimeRegistry();
        $registrar = new RuntimeApplicationRegistrar($registry);

        $registrar->register(static function ($runtime): void {
            $runtime->resource('app.settings')->hook('create.before', ApplicationRuntimeHandler::class, 'before');
        });

        $descriptor = $registry->build()->toArray()['resources']['app.settings']['hooks']['create.before'][0];

        self::assertEqualsCanonicalizing(['type' => 'app', 'code' => 'host'], $descriptor['owner']);
    }
}

final class ApplicationRuntimeHandler
{
    public function before(): void
    {
    }
}
