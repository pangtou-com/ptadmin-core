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

        self::assertSame('0.1.6', $manifest['frontend_version']);
        self::assertSame('./index.html', $manifest['entry']);
    }

    public function testBundledShellProvidesVerifiedSharedImportsForApplicationModules(): void
    {
        $directory = dirname(__DIR__, 4).'/resources/admin-frontend';
        $manifest = (new AdminFrontendManifest('0.1.0', 1, 1))->verify($directory);
        $document = new \DOMDocument();
        $document->loadHTML((string) file_get_contents($directory.'/index.html'), LIBXML_NOERROR | LIBXML_NOWARNING);
        $imports = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') === 'importmap') {
                $imports = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR)['imports'];
            }
        }

        foreach (['vue', 'element-plus', '@sparqjs/plugin-sdk/runtime', '@sparqjs/schema-vue'] as $specifier) {
            self::assertArrayHasKey($specifier, $imports);
            self::assertStringStartsWith('./assets/', $imports[$specifier]);
            self::assertContains(substr($imports[$specifier], 2), array_column($manifest['files'], 'path'));
        }
    }
}
