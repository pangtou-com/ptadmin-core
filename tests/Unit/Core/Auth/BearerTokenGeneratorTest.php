<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Auth\Session\BearerTokenGenerator;

final class BearerTokenGeneratorTest extends TestCase
{
    public function testTokensAreOpaqueAndHashable(): void
    {
        $generator = new BearerTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        self::assertSame(64, strlen($generator->hash($first)));
        self::assertNotSame($first, $generator->hash($first));
    }
}
