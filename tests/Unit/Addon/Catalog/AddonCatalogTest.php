<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Catalog;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Exception\AddonException;
use Symfony\Component\Process\Process;

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
        mkdir($this->root.'/valid-addon/src', 0700, true);
        file_put_contents($this->root.'/valid-addon/src/Bootstrap.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        $this->remove($this->lifecyclePath);
    }

    public function testScanDiscoversInstalledAddonAndIgnoresHiddenDirectories(): void
    {
        $result = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath)))->scan();
        self::assertCount(1, $result['addons']);
        self::assertSame('valid-addon', $result['addons'][0]['code']);
        self::assertArrayNotHasKey('verified_files', $result['addons'][0]['manifest']);
        self::assertSame([], $result['errors']);
        $again = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath)))->scan();
        self::assertCount(1, $again['addons']);
    }

    public function testScanReturnsDiagnosticForInvalidAddon(): void
    {
        mkdir($this->root.'/invalid', 0700, true);
        file_put_contents($this->root.'/invalid/manifest.json', '{}');
        $result = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecyclePath)))->scan();
        self::assertCount(1, $result['errors']);
        self::assertSame('invalid_addon_manifest', $result['errors'][0]['error_code']);
    }

    public function testRequestsUsePublishedManifestWithoutRescanningOrWritingLifecycle(): void
    {
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $lifecycle->transition('valid-addon', 'active', ['manifest' => ['code' => 'valid-addon', 'version' => '1.0.0']]);
        $before = file_get_contents($this->lifecyclePath.'/valid-addon/state.json');
        $catalog = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
        $snapshot = $catalog->scan();
        $published = file_get_contents($lifecycle->catalogPath());
        file_put_contents($this->root.'/valid-addon/manifest.json', '{broken');
        for ($index = 0; $index < 5; $index++) {
            $request = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
            self::assertSame($snapshot, $request->scan());
            self::assertSame($snapshot, $request->scan());
        }
        self::assertSame($before, file_get_contents($this->lifecyclePath.'/valid-addon/state.json'));
        self::assertSame($published, file_get_contents($lifecycle->catalogPath()));
        self::assertSame('invalid_addon_manifest', $catalog->rebuild()['errors'][0]['error_code']);
    }

    public function testRebuildPreservesDisabledAndDisposedStatesAndDoesNotActivateUnfinishedOperations(): void
    {
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $catalog = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
        foreach (['inactive', 'loading', 'disposed'] as $state) {
            $lifecycle->restore('valid-addon', ['state' => $state, 'disabled_by_user' => $state === 'inactive']);
            $snapshot = $catalog->rebuild();
            self::assertSame($state, $lifecycle->read('valid-addon')['state']);
            if ($state === 'disposed') {
                self::assertSame([], $snapshot['addons']);
            } else {
                self::assertSame($state, $snapshot['addons'][0]['state']);
            }
        }
    }

    public function testCorruptSnapshotRequiresExplicitRebuild(): void
    {
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $catalog = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
        file_put_contents($lifecycle->catalogPath(), '{broken');
        try {
            $catalog->scan();
            self::fail('损坏快照不能作为空插件列表返回。');
        } catch (AddonException $exception) {
            self::assertSame('addon_catalog_invalid', $exception->errorCode);
        }
        self::assertCount(1, $catalog->rebuild()['addons']);
        self::assertCount(1, $catalog->scan()['addons']);
    }

    public function testReadersDoNotWaitForAnOperationAndNestedWritersFailWithoutDeadlocking(): void
    {
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $catalog = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
        $lock = $catalog->beginOperation();
        try {
            $other = new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle);
            self::assertSame('active', $other->scan()['addons'][0]['state']);
            try {
                $other->beginOperation();
                self::fail('并发变更必须拒绝，不能等待自身持有的文件锁。');
            } catch (AddonException $exception) {
                self::assertSame('addon_busy', $exception->errorCode);
            }
        } finally {
            $catalog->endOperation($lock);
        }
        $next = $catalog->beginOperation();
        $catalog->endOperation($next);
    }

    public function testConcurrentColdStartsAndRepeatedReadsNeverLosePlugins(): void
    {
        $script = <<<'PHP'
require $argv[1];
$lifecycle = new \PTAdmin\Addon\Lifecycle\LifecycleStore($argv[3]);
for ($i = 0; $i < 50; $i++) {
    $catalog = new \PTAdmin\Addon\Catalog\AddonCatalog($argv[2], new \PTAdmin\Addon\Manifest\ManifestVerifier(), $lifecycle);
    $scan = $catalog->scan();
    if ($scan['errors'] !== [] || array_column($scan['addons'], 'code') !== ['valid-addon'] || $scan['addons'][0]['state'] !== 'active') {
        fwrite(STDERR, json_encode($scan));
        exit(1);
    }
}
PHP;
        $processes = [];
        try {
            for ($index = 0; $index < 6; $index++) {
                $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 4).'/vendor/autoload.php', $this->root, $this->lifecyclePath]);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
            }
            self::assertNull((new LifecycleStore($this->lifecyclePath))->read('valid-addon'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
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
