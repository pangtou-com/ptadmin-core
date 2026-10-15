<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\ReleaseConfiguration;

final class ReleaseBusinessCapabilitiesTest extends TestCase
{
    public function testOptionalBusinessDeclarationsKeepTheirNamesChannelsAndDescriptions(): void
    {
        self::assertNull((new ReleaseConfiguration([]))->businessCapabilities());
        self::assertSame([], (new ReleaseConfiguration(['business_capabilities' => []]))->businessCapabilities());
        $config = new ReleaseConfiguration(['business_capabilities' => [['code' => 'payment', 'name' => '支付']]]);
        self::assertSame([['code' => 'payment', 'name' => '支付', 'channels' => [], 'description' => '']], $config->businessCapabilities());
    }

    /** @dataProvider invalidDeclarations */
    public function testInvalidBusinessDeclarationsAreRejected(mixed $value): void
    {
        $this->expectException(CloudException::class);
        new ReleaseConfiguration(['business_capabilities' => $value]);
    }

    public static function invalidDeclarations(): array
    {
        $payment = ['code' => 'payment', 'name' => '支付'];
        return [
            [null], ['payment'], [['payment']], [[['code' => 'payment']]],
            [[$payment, $payment]], [[array_replace($payment, ['channels' => ['']])]],
            [[array_replace($payment, ['name' => str_repeat('支', 61)])]],
            [[array_replace($payment, ['description' => str_repeat('支', 2001)])]],
            [[array_replace($payment, ['channels' => '支付宝'])]],
            [[array_replace($payment, ['code' => '../payment'])]],
            [[array_replace($payment, ['unrecognized' => true])]],
        ];
    }
}
