<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use PTAdmin\Cloud\EncryptedCloudSessionStore;

final class EncryptedCloudSessionStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-cloud-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testSessionIsEncryptedAndCanBeReadBack(): void
    {
        $path = $this->directory.'/session.json';
        $store = new EncryptedCloudSessionStore($path, new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $store->put(['access_token' => 'platform-secret', 'user' => ['id' => 'u-1']]);

        self::assertStringNotContainsString('platform-secret', (string) file_get_contents($path));
        self::assertSame('platform-secret', $store->get()['access_token']);

        $store->forget();
        self::assertNull($store->get());
    }
}
