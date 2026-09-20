<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Lifecycle;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Exception\AddonException;

final class LifecycleStoreTest extends TestCase
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
        $store = new LifecycleStore($this->directory);
        self::assertSame('discovered', $store->transition('demo', 'discovered')['state']);
        self::assertSame('validating', $store->transition('demo', 'validating')['state']);
        self::assertSame('loading', $store->transition('demo', 'loading')['state']);
        self::assertSame('ready', $store->transition('demo', 'ready')['state']);
        self::assertSame('active', $store->transition('demo', 'active')['state']);

        $this->expectException(AddonException::class);
        $store->transition('demo', 'discovered');
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
