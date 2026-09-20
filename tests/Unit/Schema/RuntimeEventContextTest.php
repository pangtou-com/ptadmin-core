<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Runtime\RuntimeEventContext;
use RuntimeException;

final class RuntimeEventContextTest extends TestCase
{
    public function testContextRoundTripsAsSerializableArray(): void
    {
        $context = new RuntimeEventContext(
            'event-1',
            'created',
            'cms.articles',
            10,
            ['type' => 'admin', 'id' => '2'],
            '2026-09-19T12:00:00+08:00',
            'request-1',
            ['data' => ['title' => 'Hello']],
            'idempotency-1',
        );

        self::assertSame($context->toArray(), RuntimeEventContext::fromArray($context->toArray())->toArray());
    }

    public function testContextRejectsNonSerializableObjectPayload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot contain a model');

        new RuntimeEventContext(
            'event-1',
            'created',
            'cms.articles',
            null,
            [],
            '2026-09-19T12:00:00+08:00',
            '',
            ['invalid' => new \DateTimeImmutable()],
            'idempotency-1',
        );
    }
}
