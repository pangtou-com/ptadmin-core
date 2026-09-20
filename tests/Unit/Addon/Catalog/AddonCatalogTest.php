<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Catalog;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;

final class AddonCatalogTest extends TestCase
{
    private string $root;
    private string $lifecyclePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-discovery-'.bin2hex(random_bytes(5));
        $this->lifecyclePath = sys_get_temp_dir().'/ptadmin-addon-discovery-state-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/valid-addon', 0700, true);
        mkdir($this->root.'/.staging', 0700, true);
        file_put_contents($this->root.'/valid-addon/manifest.json', json_encode(['code' => 'valid-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/valid-addon/Bootstrap.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        $this->remove($this->lifecyclePath);
    }

    public function testScanDiscoversInstalledAddonAndIgnoresHiddenDirectories(): void
    {
        $result = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath), false))->scan();
        self::assertCount(1, $result['addons']);
        self::assertSame('valid-addon', $result['addons'][0]['code']);
        self::assertSame([], $result['errors']);
        $again = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath), false))->scan();
        self::assertCount(1, $again['addons']);
    }

    public function testScanReturnsDiagnosticForInvalidAddon(): void
    {
        mkdir($this->root.'/invalid', 0700, true);
        file_put_contents($this->root.'/invalid/manifest.json', '{}');
        $result = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath), false))->scan();
        self::assertCount(1, $result['errors']);
        self::assertSame('invalid_addon_manifest', $result['errors'][0]['error_code']);
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
