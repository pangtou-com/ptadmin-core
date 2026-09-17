<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonManifestException;
use PTAdmin\Addon\AddonTrustPolicy;

final class AddonTrustPolicyTest extends TestCase
{
    public function testTrustedManifestRequiresConfiguredPublisherAndKey(): void
    {
        $policy = new AddonTrustPolicy(
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
        $this->expectException(AddonManifestException::class);
        (new AddonTrustPolicy(['release-1' => 'expected'], ['vendor.example']))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'vendor.example',
            'signature' => ['key_id' => 'other', 'public_key' => 'actual'],
        ]);
    }

    public function testIframeManifestDoesNotRequireTrustConfiguration(): void
    {
        (new AddonTrustPolicy())->assertTrusted(['sandbox' => 'iframe']);
        self::assertTrue(true);
    }

    public function testUnsignedLocalTrustedManifestRequiresExplicitDevelopmentPolicy(): void
    {
        (new AddonTrustPolicy([], [], true, 'testing'))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'local:demo',
        ]);
        self::assertTrue(true);

        $this->expectException(AddonManifestException::class);
        (new AddonTrustPolicy([], [], false, 'testing'))->assertTrusted([
            'sandbox' => 'trusted',
            'publisher' => 'local:demo',
        ]);
    }
}
