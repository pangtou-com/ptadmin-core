<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Frontend;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use RuntimeException;

final class FrontendDevelopmentStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ptadmin-frontend-dev-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->path);
    }

    public function testLocalRegistrationCanBeResolvedAndExplicitlyRemoved(): void
    {
        $store = new FrontendDevelopmentStore($this->path, 'testing', [], 120);
        $registered = $store->register('demo-addon', 'http://127.0.0.1:5174/src/root.ts', 'trusted', 'dev-2', 123);

        self::assertSame('http://127.0.0.1:5174', $registered['allowed_origin']);
        self::assertSame('dev-2', $store->resolve('demo-addon')['dev_revision']);
        self::assertTrue($store->unregister('demo-addon'));
        self::assertNull($store->resolve('demo-addon'));
    }

    public function testProductionIgnoresRegistrationFiles(): void
    {
        $local = new FrontendDevelopmentStore($this->path, 'local');
        $local->register('demo-addon', 'http://localhost:5174/index.html', 'iframe');

        self::assertNull((new FrontendDevelopmentStore($this->path, 'production'))->resolve('demo-addon'));
    }

    public function testNonLoopbackOriginRequiresExplicitAllowance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Origin 未被宿主允许');

        (new FrontendDevelopmentStore($this->path, 'local'))->register(
            'demo-addon',
            'https://dev.example.test/index.html',
            'iframe',
        );
    }

    public function testRegistrationPathsRejectInvalidAddonCodes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('插件编码格式无效');

        (new FrontendDevelopmentStore($this->path, 'local'))->unregister('../outside');
    }
}
