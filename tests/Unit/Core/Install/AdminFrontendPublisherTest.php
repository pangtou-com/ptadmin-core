<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Core\Install\AdminFrontendPublisher;

final class AdminFrontendPublisherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-frontend-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testUpdatePreservesPluginModulesAndPublishesVerifiedBundle(): void
    {
        if (!class_exists(\Composer\Semver\Semver::class)) {
            self::markTestSkipped('composer/semver is not installed.');
        }
        $source = dirname(__DIR__, 4).'/resources/admin-frontend';
        $publisher = new AdminFrontendPublisher(
            $source,
            $this->directory.'/public',
            new AdminFrontendManifest('0.1.0', 1, 1)
        );
        $publisher->publishForInstall('admin_'.bin2hex(random_bytes(3)));
        $prefix = (string) basename((string) glob($this->directory.'/public/*', GLOB_ONLYDIR)[0]);
        $module = $this->directory.'/public/'.$prefix.'/modules/demo';
        mkdir($module, 0700, true);
        file_put_contents($module.'/manifest.json', '{}');

        $result = $publisher->publishForUpdate($prefix);

        self::assertTrue($result['published']);
        self::assertFileExists($module.'/manifest.json');
        self::assertFileExists($this->directory.'/public/'.$prefix.'/index.html');
    }

    public function testInstallPublishesToNestedCustomPrefix(): void
    {
        if (!class_exists(\Composer\Semver\Semver::class)) {
            self::markTestSkipped('composer/semver is not installed.');
        }
        $source = dirname(__DIR__, 4).'/resources/admin-frontend';
        $publisher = new AdminFrontendPublisher(
            $source,
            $this->directory.'/public',
            new AdminFrontendManifest('0.1.0', 1, 1)
        );

        $result = $publisher->publishForInstall('manage/console');

        self::assertTrue($result['published']);
        self::assertSame($this->directory.'/public/manage/console', $result['path']);
        self::assertFileExists($result['path'].'/index.html');
        self::assertDirectoryExists($result['path'].'/modules');
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        @rmdir($path);
    }
}
