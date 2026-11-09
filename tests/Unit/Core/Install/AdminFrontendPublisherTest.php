<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Core\Install\AdminFrontendPublisher;
use PTAdmin\Core\Install\WebPrefixGenerator;

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
            new AdminFrontendManifest('0.6.0', 1, 1)
        );
        $prefix = (new WebPrefixGenerator())->generate();
        $publisher->publishForInstall($prefix);
        $module = $this->directory.'/public/'.$prefix.'/modules/demo';
        mkdir($module, 0700, true);
        file_put_contents($module.'/manifest.json', '{}');
        $plugin = $this->directory.'/public/'.$prefix.'/plugins/cms';
        mkdir($plugin, 0700, true);
        file_put_contents($plugin.'/plugin.mjs', 'export default "installed-plugin";');
        $manifestPath = $this->directory.'/public/'.$prefix.'/manifest.json';
        $oldManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $oldManifest['frontend_version'] = '0.1.0';
        file_put_contents($manifestPath, json_encode($oldManifest, JSON_THROW_ON_ERROR));

        // 已安装站点不会通过再次调用安装发布来替换旧 Shell，必须走显式更新。
        self::assertSame('0.1.0', $publisher->publishForInstall($prefix)['frontend_version']);

        $result = $publisher->publishForUpdate($prefix);

        self::assertTrue($result['published']);
        self::assertSame('0.5.1', $result['frontend_version']);
        self::assertFileExists($module.'/manifest.json');
        self::assertSame('{}', file_get_contents($module.'/manifest.json'));
        self::assertSame('export default "installed-plugin";', file_get_contents($plugin.'/plugin.mjs'));
        self::assertSame('0.5.1', json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)['frontend_version']);
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
            new AdminFrontendManifest('0.6.0', 1, 1)
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
