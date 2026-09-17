<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonManifestException;

final class AddonLifecycleStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-addon-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testLifecycleTransitionsArePersistedAndInvalidTransitionsRejected(): void
    {
        $store = new AddonLifecycleStore($this->directory);
        self::assertSame('discovered', $store->transition('cms', 'discovered')['state']);
        self::assertSame('validating', $store->transition('cms', 'validating')['state']);
        self::assertSame('loading', $store->transition('cms', 'loading')['state']);
        self::assertSame('ready', $store->transition('cms', 'ready')['state']);
        self::assertSame('active', $store->transition('cms', 'active')['state']);

        $this->expectException(AddonManifestException::class);
        $store->transition('cms', 'discovered');
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
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        @rmdir($path);
    }
}
