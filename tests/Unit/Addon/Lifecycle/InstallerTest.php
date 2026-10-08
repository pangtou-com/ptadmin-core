<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Lifecycle;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Runtime\AddonAutoloader;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use RuntimeException;

final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-install-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testInstallPublishesPackageAndFrontendAtomically(): void
    {
        $source = $this->root.'/source';
        mkdir($source.'/frontend/dist', 0700, true);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        mkdir($source.'/src', 0700, true);
        file_put_contents($source.'/src/Bootstrap.php', '<?php');
        file_put_contents($source.'/frontend/dist/entry.js', 'export default {}');

        $public = $this->root.'/public';
        $installer = new Installer(
            new ManifestVerifier('0.1.0', 1),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($public),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );
        $result = $installer->install($source, 'admintest');

        self::assertSame('demo-addon', $result['code']);
        self::assertFileExists($this->root.'/addons/demo-addon/src/Bootstrap.php');
        self::assertFileExists($public.'/admintest/plugins/demo-addon/entry.js');
    }

    public function testInstallAndUninstallClearRuntimeCache(): void
    {
        $source = $this->root.'/source';
        mkdir($source, 0700, true);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $cache = new RuntimeCacheStore($this->root.'/cache/ptadmin-runtime.php');
        $this->writeRuntimeCache($cache);
        $installer = new Installer(
            new ManifestVerifier(),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
            null,
            null,
            null,
            $cache,
        );

        $installer->install($source, 'admintest');
        self::assertFileDoesNotExist($this->root.'/cache/ptadmin-runtime.php');

        $this->writeRuntimeCache($cache);
        $installer->uninstall('demo-addon', 'admintest');
        self::assertFileDoesNotExist($this->root.'/cache/ptadmin-runtime.php');
    }

    public function testUninstallRemovesCodeAndFrontendButLeavesDataOutsideAddonPath(): void
    {
        $source = $this->root.'/source';
        mkdir($source, 0700, true);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $installer = new Installer(new ManifestVerifier(), new PackageExtractor(), new LifecycleStore($this->root.'/state'), new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($source, 'admintest');
        file_put_contents($this->root.'/business-data.json', '{}');
        $result = $installer->uninstall('demo-addon', 'admintest');

        self::assertTrue($result['data_preserved']);
        self::assertDirectoryDoesNotExist($this->root.'/addons/demo-addon');
        self::assertDirectoryDoesNotExist($this->root.'/public/admintest/plugins/demo-addon');
        self::assertFileExists($this->root.'/business-data.json');
    }

    public function testFailedUpdateRestoresBackendFrontendAndLifecycle(): void
    {
        $state = new LifecycleStore($this->root.'/state');
        $publisher = new FrontendPublisher($this->root.'/public');
        $autoloader = new AddonAutoloader();
        $installer = new Installer(new ManifestVerifier(), new PackageExtractor(), $state, $publisher, $this->root.'/addons', $this->root.'/staging', false, autoloader: $autoloader);
        $versionOne = $this->package('v1', '1.0.0', 'backend-v1', 'frontend-v1');
        mkdir($versionOne.'/src');
        $installer->install($versionOne, 'admintest');
        $previousMapping = $autoloader->snapshot('demo-addon');

        $failingSync = new class implements ResourceSynchronizerInterface {
            public function sync(string $directory, string $code): array
            {
                throw new RuntimeException('sync failed');
            }

            public function deactivate(string $code): void
            {
            }
        };
        $updater = new Installer(new ManifestVerifier(), new PackageExtractor(), $state, $publisher, $this->root.'/addons', $this->root.'/staging', false, $failingSync, autoloader: $autoloader);

        try {
            $updater->install($this->package('v2', '2.0.0', 'backend-v2', 'frontend-v2'), 'admintest', true);
            self::fail('The update must fail when resource synchronization fails.');
        } catch (RuntimeException $exception) {
            self::assertSame('sync failed', $exception->getMessage());
        }

        self::assertSame('backend-v1', file_get_contents($this->root.'/addons/demo-addon/backend.txt'));
        self::assertSame('frontend-v1', file_get_contents($this->root.'/public/admintest/plugins/demo-addon/entry.js'));
        self::assertSame('1.0.0', $state->read('demo-addon')['version']);
        self::assertSame('active', $state->read('demo-addon')['state']);
        self::assertSame($previousMapping, $autoloader->snapshot('demo-addon'));
    }

    public function testUpdatingTheSameDirectoryLoadsAPreviouslyMissingNewClass(): void
    {
        $namespace = 'UpdatedAddon'.bin2hex(random_bytes(5));
        $autoloader = new AddonAutoloader();
        $installer = new Installer(
            new ManifestVerifier(), new PackageExtractor(), new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false,
            autoloader: $autoloader,
        );
        foreach (['v1' => '1.0.0', 'v2' => '2.0.0'] as $folder => $version) {
            $source = $this->package($folder, $version, $folder, $folder);
            mkdir($source.'/src');
            file_put_contents($source.'/manifest.json', json_encode([
                'code' => 'demo-addon', 'version' => $version, 'namespace' => $namespace,
            ], JSON_THROW_ON_ERROR));
        }
        $installer->install($this->root.'/v1', 'admintest');
        self::assertFalse(class_exists($namespace.'\\Added'));
        file_put_contents($this->root.'/v2/src/Added.php', '<?php namespace '.$namespace.'; final class Added {}');
        $installer->install($this->root.'/v2', 'admintest', true);

        self::assertTrue(class_exists($namespace.'\\Added'));
        self::assertSame(realpath($this->root.'/addons/demo-addon/src/Added.php'), (new \ReflectionClass($namespace.'\\Added'))->getFileName());
    }

    public function testDisposedAddonCanBeInstalledAgain(): void
    {
        $state = new LifecycleStore($this->root.'/state');
        $installer = new Installer(new ManifestVerifier(), new PackageExtractor(), $state, new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $source = $this->package('v1', '1.0.0', 'backend-v1', 'frontend-v1');
        $installer->install($source, 'admintest');
        $installer->uninstall('demo-addon', 'admintest');
        $installer->install($source, 'admintest');

        self::assertSame('active', $state->read('demo-addon')['state']);
        self::assertFileExists($this->root.'/addons/demo-addon/backend.txt');
    }

    public function testInstallRejectsAnArtifactWithAnUnexpectedChecksum(): void
    {
        $artifact = $this->root.'/addon.zip';
        file_put_contents($artifact, 'invalid package');
        $installer = new Installer(
            new ManifestVerifier(),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );

        try {
            $installer->install($artifact, 'admintest', false, null, str_repeat('0', 64));
            self::fail('Checksum mismatch must be rejected before extraction.');
        } catch (AddonException $exception) {
            self::assertSame('checksum_mismatch', $exception->errorCode);
            self::assertDirectoryDoesNotExist($this->root.'/staging');
        }
    }

    public function testInstallDoesNotApplyAnArtifactChecksumToADirectorySource(): void
    {
        $source = $this->package('source', '1.0.0', 'backend', 'frontend');
        $installer = new Installer(
            new ManifestVerifier(),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );

        $this->expectException(AddonException::class);
        $this->expectExceptionMessage('local package artifact');
        $installer->install($source, 'admintest', false, null, str_repeat('0', 64));
    }

    public function testSyncFrontendValidatesDescriptorAndPublishesWithoutTrustVerification(): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/frontend/dist', 0700, true);
        file_put_contents($addon.'/frontend/dist/entry.js', 'export default {}');
        file_put_contents($addon.'/frontend/frontend.json', json_encode([
            'host_api' => 'admin-v1',
            'entries' => [[
                'entry' => 'entry.js',
                'sandbox' => 'trusted',
            ]],
        ], JSON_THROW_ON_ERROR));

        $installer = new Installer(
            new ManifestVerifier(),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            true,
        );

        $result = $installer->syncFrontend('demo-addon', 'admintest');

        self::assertTrue($result['published']);
        self::assertFileExists($this->root.'/public/admintest/plugins/demo-addon/entry.js');
    }

    public function testSyncFrontendRejectsInvalidDescriptorJson(): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/frontend/dist', 0700, true);
        file_put_contents($addon.'/frontend/dist/entry.js', 'export default {}');
        file_put_contents($addon.'/frontend/frontend.json', '{invalid');
        $installer = $this->frontendSyncInstaller();

        try {
            $installer->syncFrontend('demo-addon', 'admintest');
            self::fail('Invalid frontend descriptor JSON must be rejected.');
        } catch (AddonException $exception) {
            self::assertSame('frontend_descriptor_invalid', $exception->errorCode);
        }
    }

    public function testSyncFrontendRejectsUnavailableEntry(): void
    {
        $this->writeFrontendDescriptor('missing.js', 'trusted');
        $installer = $this->frontendSyncInstaller();

        try {
            $installer->syncFrontend('demo-addon', 'admintest');
            self::fail('An unavailable frontend entry must be rejected.');
        } catch (AddonException $exception) {
            self::assertSame('frontend_entry_unavailable', $exception->errorCode);
        }
    }

    public function testSyncFrontendRejectsEntryTypeThatDoesNotMatchSandbox(): void
    {
        $this->writeFrontendDescriptor('entry.html', 'trusted');
        file_put_contents($this->root.'/addons/demo-addon/frontend/dist/entry.html', '<!doctype html>');
        $installer = $this->frontendSyncInstaller();

        try {
            $installer->syncFrontend('demo-addon', 'admintest');
            self::fail('A frontend entry with an invalid sandbox type must be rejected.');
        } catch (AddonException $exception) {
            self::assertSame('frontend_entry_type_invalid', $exception->errorCode);
        }
    }

    private function frontendSyncInstaller(): Installer
    {
        return new Installer(
            new ManifestVerifier(),
            new PackageExtractor(),
            new LifecycleStore($this->root.'/state'),
            new FrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            true,
        );
    }

    private function writeFrontendDescriptor(string $entry, string $sandbox): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/frontend/dist', 0700, true);
        file_put_contents($addon.'/frontend/frontend.json', json_encode([
            'host_api' => 'admin-v1',
            'entries' => [[
                'entry' => $entry,
                'sandbox' => $sandbox,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function package(string $name, string $version, string $backend, string $frontend): string
    {
        $source = $this->root.'/'.$name;
        mkdir($source.'/frontend/dist', 0700, true);
        file_put_contents($source.'/backend.txt', $backend);
        file_put_contents($source.'/frontend/dist/entry.js', $frontend);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => $version], JSON_THROW_ON_ERROR));
        return $source;
    }

    private function writeRuntimeCache(RuntimeCacheStore $cache): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cache.marker');
        $cache->write($registry->build());
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $entry = $path.'/'.$name;
            is_dir($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
