<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Manifest;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\TrustPolicy;

final class TrustPolicyTest extends TestCase
{
    public function testTrustedManifestRequiresConfiguredPublisherAndKey(): void
    {
        $policy = new TrustPolicy(
            ['release-1' => "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----"],
            ['vendor.example']
        );

        $policy->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'vendor.example',
            'signature' => [
                'key_id' => 'release-1',
                'public_key' => "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----",
            ],
        ]);
        self::assertTrue(true);
    }

    public function testUnknownTrustedKeyIsRejected(): void
    {
        $this->expectException(AddonException::class);
        (new TrustPolicy(['release-1' => 'expected'], ['vendor.example']))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'vendor.example',
            'signature' => ['key_id' => 'other', 'public_key' => 'actual'],
        ]);
    }

    public function testIframeManifestDoesNotRequireTrustConfiguration(): void
    {
        (new TrustPolicy())->assertTrusted(['sandbox' => 'iframe']);
        self::assertTrue(true);
    }

    public function testUnsignedLocalTrustedManifestRequiresExplicitDevelopmentPolicy(): void
    {
        (new TrustPolicy([], [], true, 'testing'))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'local:demo',
        ]);
        self::assertTrue(true);

        $this->expectException(AddonException::class);
        (new TrustPolicy([], [], false, 'testing'))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'local:demo',
        ]);
    }
}
