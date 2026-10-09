<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\ReleaseVersion;

final class ReleaseVersionTest extends TestCase
{
    public function testFirstReleaseAndLocalVersionAheadPreferLocal(): void
    {
        self::assertSame('1.0.0', ReleaseVersion::suggestions('1.0.0', null)['local']);
        $next = ReleaseVersion::suggestions('2.0.0', '1.9.0');
        self::assertSame('2.0.0', $next['local']);
        self::assertSame('2.0.1', $next['patch']);
    }

    public function testExistingAndOlderLocalVersionsSuggestPlatformIncrement(): void
    {
        foreach (['1.0.0', '1.10.2'] as $local) {
            self::assertSame(['local' => null, 'patch' => '1.10.3', 'minor' => '1.11.0', 'major' => '2.0.0'], ReleaseVersion::suggestions($local, '1.10.2'));
        }
    }

    public function testPrereleaseAndBuildMetadataDoNotDistortVersionOrdering(): void
    {
        self::assertSame('1.0.0', ReleaseVersion::suggestions('1.0.0', '1.0.0-rc.1')['local']);
        self::assertNull(ReleaseVersion::suggestions('1.0.0+build.2', '1.0.0+build.1')['local']);
        self::assertSame('2.0.1', ReleaseVersion::suggestions('1.0.0', '2.0.0-beta.1')['patch']);
    }

    public function testInvalidVersionsAreRejectedInsteadOfGuessed(): void
    {
        foreach (['', 'latest', '1.2', '../1.0.0', 'v1.0.0', '1.0.0<script>'] as $version) {
            try {
                ReleaseVersion::validate($version);
                self::fail('Invalid release version accepted: '.$version);
            } catch (CloudException $exception) {
                self::assertSame('invalid_request', $exception->errorCode);
            }
        }
    }
}
