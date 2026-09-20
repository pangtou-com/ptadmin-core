<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonFrontendPublisher;
use PTAdmin\Addon\AddonInstaller;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonManifestException;
use PTAdmin\Addon\AddonManifestVerifier;
use PTAdmin\Addon\AddonPackageExtractor;
use PTAdmin\Addon\AddonResourceSynchronizerInterface;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use RuntimeException;

final class AddonInstallerTest extends TestCase
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
        mkdir($source.'/Frontend/dist', 0700, true);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        file_put_contents($source.'/Bootstrap.php', '<?php');
        file_put_contents($source.'/Frontend/dist/entry.js', 'export default {}');

        $public = $this->root.'/public';
        $installer = new AddonInstaller(
            new AddonManifestVerifier('0.1.0', 1),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($public),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );
        $result = $installer->install($source, 'admintest');

        self::assertSame('demo-addon', $result['code']);
        self::assertFileExists($this->root.'/addons/demo-addon/Bootstrap.php');
        self::assertFileExists($public.'/admintest/modules/demo-addon/entry.js');
    }

    public function testInstallAndUninstallClearRuntimeCache(): void
    {
        $source = $this->root.'/source';
        mkdir($source, 0700, true);
        file_put_contents($source.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $cache = new RuntimeCacheStore($this->root.'/cache/ptadmin-runtime.php');
        $this->writeRuntimeCache($cache);
        $installer = new AddonInstaller(
            new AddonManifestVerifier(),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($this->root.'/public'),
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
        $installer = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), new AddonLifecycleStore($this->root.'/state'), new AddonFrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($source, 'admintest');
        file_put_contents($this->root.'/business-data.json', '{}');
        $result = $installer->uninstall('demo-addon', 'admintest');

        self::assertTrue($result['data_preserved']);
        self::assertDirectoryDoesNotExist($this->root.'/addons/demo-addon');
        self::assertDirectoryDoesNotExist($this->root.'/public/admintest/modules/demo-addon');
        self::assertFileExists($this->root.'/business-data.json');
    }

    public function testFailedUpdateRestoresBackendFrontendAndLifecycle(): void
    {
        $state = new AddonLifecycleStore($this->root.'/state');
        $publisher = new AddonFrontendPublisher($this->root.'/public');
        $installer = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), $state, $publisher, $this->root.'/addons', $this->root.'/staging', false);
        $versionOne = $this->package('v1', '1.0.0', 'backend-v1', 'frontend-v1');
        $installer->install($versionOne, 'admintest');

        $failingSync = new class implements AddonResourceSynchronizerInterface {
            public function sync(string $directory, string $code): array
            {
                throw new RuntimeException('sync failed');
            }

            public function deactivate(string $code): void
            {
            }
        };
        $updater = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), $state, $publisher, $this->root.'/addons', $this->root.'/staging', false, $failingSync);

        try {
            $updater->install($this->package('v2', '2.0.0', 'backend-v2', 'frontend-v2'), 'admintest', true);
            self::fail('The update must fail when resource synchronization fails.');
        } catch (RuntimeException $exception) {
            self::assertSame('sync failed', $exception->getMessage());
        }

        self::assertSame('backend-v1', file_get_contents($this->root.'/addons/demo-addon/backend.txt'));
        self::assertSame('frontend-v1', file_get_contents($this->root.'/public/admintest/modules/demo-addon/entry.js'));
        self::assertSame('1.0.0', $state->read('demo-addon')['version']);
        self::assertSame('active', $state->read('demo-addon')['state']);
    }

    public function testDisposedAddonCanBeInstalledAgain(): void
    {
        $state = new AddonLifecycleStore($this->root.'/state');
        $installer = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), $state, new AddonFrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
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
        $installer = new AddonInstaller(
            new AddonManifestVerifier(),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );

        try {
            $installer->install($artifact, 'admintest', false, null, str_repeat('0', 64));
            self::fail('Checksum mismatch must be rejected before extraction.');
        } catch (AddonManifestException $exception) {
            self::assertSame('checksum_mismatch', $exception->errorCode);
            self::assertDirectoryDoesNotExist($this->root.'/staging');
        }
    }

    public function testInstallDoesNotApplyAnArtifactChecksumToADirectorySource(): void
    {
        $source = $this->package('source', '1.0.0', 'backend', 'frontend');
        $installer = new AddonInstaller(
            new AddonManifestVerifier(),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            false,
        );

        $this->expectException(AddonManifestException::class);
        $this->expectExceptionMessage('local package artifact');
        $installer->install($source, 'admintest', false, null, str_repeat('0', 64));
    }

    public function testSyncFrontendValidatesDescriptorAndPublishesWithoutTrustVerification(): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/Frontend/dist', 0700, true);
        file_put_contents($addon.'/Frontend/dist/entry.js', 'export default {}');
        file_put_contents($addon.'/Frontend/frontend.json', json_encode([
            'host_api' => 'admin-v1',
            'entries' => [[
                'entry' => 'entry.js',
                'sandbox' => 'trusted',
            ]],
        ], JSON_THROW_ON_ERROR));

        $installer = new AddonInstaller(
            new AddonManifestVerifier(),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            true,
        );

        $result = $installer->syncFrontend('demo-addon', 'admintest');

        self::assertTrue($result['published']);
        self::assertFileExists($this->root.'/public/admintest/modules/demo-addon/entry.js');
    }

    public function testSyncFrontendRejectsInvalidDescriptorJson(): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/Frontend/dist', 0700, true);
        file_put_contents($addon.'/Frontend/dist/entry.js', 'export default {}');
        file_put_contents($addon.'/Frontend/frontend.json', '{invalid');
        $installer = $this->frontendSyncInstaller();

        try {
            $installer->syncFrontend('demo-addon', 'admintest');
            self::fail('Invalid frontend descriptor JSON must be rejected.');
        } catch (AddonManifestException $exception) {
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
        } catch (AddonManifestException $exception) {
            self::assertSame('frontend_entry_unavailable', $exception->errorCode);
        }
    }

    public function testSyncFrontendRejectsEntryTypeThatDoesNotMatchSandbox(): void
    {
        $this->writeFrontendDescriptor('entry.html', 'trusted');
        file_put_contents($this->root.'/addons/demo-addon/Frontend/dist/entry.html', '<!doctype html>');
        $installer = $this->frontendSyncInstaller();

        try {
            $installer->syncFrontend('demo-addon', 'admintest');
            self::fail('A frontend entry with an invalid sandbox type must be rejected.');
        } catch (AddonManifestException $exception) {
            self::assertSame('frontend_entry_type_invalid', $exception->errorCode);
        }
    }

    private function frontendSyncInstaller(): AddonInstaller
    {
        return new AddonInstaller(
            new AddonManifestVerifier(),
            new AddonPackageExtractor(),
            new AddonLifecycleStore($this->root.'/state'),
            new AddonFrontendPublisher($this->root.'/public'),
            $this->root.'/addons',
            $this->root.'/staging',
            true,
        );
    }

    private function writeFrontendDescriptor(string $entry, string $sandbox): void
    {
        $addon = $this->root.'/addons/demo-addon';
        mkdir($addon.'/Frontend/dist', 0700, true);
        file_put_contents($addon.'/Frontend/frontend.json', json_encode([
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
        mkdir($source.'/Frontend/dist', 0700, true);
        file_put_contents($source.'/backend.txt', $backend);
        file_put_contents($source.'/Frontend/dist/entry.js', $frontend);
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
