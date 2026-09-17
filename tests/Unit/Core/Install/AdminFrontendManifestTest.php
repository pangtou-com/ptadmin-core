<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Install\AdminFrontendManifest;

final class AdminFrontendManifestTest extends TestCase
{
    public function testBundledManifestHasTheExpectedProtocolAndFiles(): void
    {
        if (!class_exists(\Composer\Semver\Semver::class)) {
            self::markTestSkipped('composer/semver is not installed.');
        }

        $manifest = (new AdminFrontendManifest('0.1.0', 1, 1))->verify(
            dirname(__DIR__, 4).'/resources/admin-frontend'
        );

        self::assertSame('0.1.0', $manifest['frontend_version']);
        self::assertSame('./index.html', $manifest['entry']);
    }
}
