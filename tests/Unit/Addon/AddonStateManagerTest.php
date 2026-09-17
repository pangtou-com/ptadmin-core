<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonDiscovery;
use PTAdmin\Addon\AddonFrontendPublisher;
use PTAdmin\Addon\AddonInstaller;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonLock;
use PTAdmin\Addon\AddonManifestVerifier;
use PTAdmin\Addon\AddonPackageExtractor;
use PTAdmin\Addon\AddonStateManager;

final class AddonStateManagerTest extends TestCase
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
        $lifecycle = new AddonLifecycleStore($this->root.'/lifecycle');
        $installer = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), $lifecycle, new AddonFrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($this->root.'/source', 'admintest');
        $states = new AddonStateManager($this->root.'/addons', new AddonManifestVerifier(), $lifecycle, new AddonLock($this->root.'/locks'), false);

        self::assertFalse($states->disable('state-addon')['enabled']);
        self::assertTrue($lifecycle->read('state-addon')['disabled_by_user']);
        self::assertTrue($states->enable('state-addon')['enabled']);
        self::assertFalse($lifecycle->read('state-addon')['disabled_by_user']);
        self::assertSame('active', $lifecycle->read('state-addon')['state']);
    }

    public function testDiscoveryLeavesExplicitlyDisabledAddonInactive(): void
    {
        $lifecycle = new AddonLifecycleStore($this->root.'/lifecycle');
        $installer = new AddonInstaller(new AddonManifestVerifier(), new AddonPackageExtractor(), $lifecycle, new AddonFrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', false);
        $installer->install($this->root.'/source', 'admintest');
        $states = new AddonStateManager($this->root.'/addons', new AddonManifestVerifier(), $lifecycle, new AddonLock($this->root.'/locks'), false);
        $states->disable('state-addon');

        $result = (new AddonDiscovery($this->root.'/addons', new AddonManifestVerifier(), $lifecycle, false))->scan();
        self::assertSame('inactive', $result['addons'][0]['state']);
        self::assertTrue($result['addons'][0]['disabled_by_user']);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) continue;
            $entry = $path.'/'.$name;
            is_dir($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
