<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Lifecycle;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Lifecycle\OperationLock;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Lifecycle\StateManager;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

final class StateManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-state-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/source', 0700, true);
        file_put_contents($this->root.'/source/manifest.json', json_encode(['code' => 'state-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/source/Bootstrap.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDisablePersistsExplicitFlagAndEnableClearsIt(): void
    {
        $lifecycle = new LifecycleStore($this->root.'/lifecycle');
        $installer = new Installer(new ManifestVerifier(), new PackageExtractor(), $lifecycle, new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($this->root.'/source', 'admintest');
        $synchronizer = new class implements ResourceSynchronizerInterface {
            /** @var list<array<int, string>> */
            public array $calls = [];

            public function sync(string $directory, string $code): array
            {
                $this->calls[] = ['sync', $directory, $code];

                return ['schemas' => 0, 'configs' => 0, 'admin_resources' => 0];
            }

            public function deactivate(string $code): void
            {
                $this->calls[] = ['deactivate', $code];
            }
        };
        $cache = new RuntimeCacheStore($this->root.'/cache/ptadmin-runtime.php');
        $states = new StateManager($this->root.'/addons', new ManifestVerifier(), $lifecycle, new OperationLock($this->root.'/locks'), false, $synchronizer, $cache);

        $this->writeRuntimeCache($cache);
        self::assertFalse($states->disable('state-addon')['enabled']);
        self::assertFileDoesNotExist($this->root.'/cache/ptadmin-runtime.php');
        self::assertTrue($lifecycle->read('state-addon')['disabled_by_user']);
        $this->writeRuntimeCache($cache);
        self::assertTrue($states->enable('state-addon')['enabled']);
        self::assertFileDoesNotExist($this->root.'/cache/ptadmin-runtime.php');
        self::assertFalse($lifecycle->read('state-addon')['disabled_by_user']);
        self::assertSame('active', $lifecycle->read('state-addon')['state']);
        self::assertSame('deactivate', $synchronizer->calls[0][0]);
        self::assertSame('sync', $synchronizer->calls[1][0]);
    }

    public function testDiscoveryLeavesExplicitlyDisabledAddonInactive(): void
    {
        $lifecycle = new LifecycleStore($this->root.'/lifecycle');
        $installer = new Installer(new ManifestVerifier(), new PackageExtractor(), $lifecycle, new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($this->root.'/source', 'admintest');
        $states = new StateManager($this->root.'/addons', new ManifestVerifier(), $lifecycle, new OperationLock($this->root.'/locks'), false);
        $states->disable('state-addon');

        $result = (new AddonCatalog($this->root.'/addons', new ManifestVerifier(), $lifecycle, false))->scan();
        self::assertSame('inactive', $result['addons'][0]['state']);
        self::assertTrue($result['addons'][0]['disabled_by_user']);
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

    private function writeRuntimeCache(RuntimeCacheStore $cache): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cache.marker');
        $cache->write($registry->build());
    }
}
