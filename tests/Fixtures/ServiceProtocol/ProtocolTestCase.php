<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, ProtocolException};

abstract class ProtocolTestCase extends TestCase
{
    protected string $temporary;
    protected LifecycleStore $lifecycle;
    protected Container $container;
    protected AddonAvailability $availability;
    protected AddonCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/ptadmin-protocol-'.bin2hex(random_bytes(8));
        $this->lifecycle = new LifecycleStore($this->temporary.'/lifecycle');
        $this->container = new Container();
        $this->catalog = new AddonCatalog($this->temporary.'/addons', new ManifestVerifier(), $this->lifecycle);
        $this->availability = new AddonAvailability($this->catalog);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temporary);
        parent::tearDown();
    }

    protected function activate(string $code): void
    {
        $path = $this->temporary.'/addons/'.$code.'/manifest.json';
        $manifest = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : ['code' => $code, 'version' => '1.0.0'];
        $lock = $this->catalog->beginOperation();
        try {
            $this->lifecycle->transition($code, 'active', ['manifest' => $manifest]);
            $this->catalog->publish($code);
        } finally {
            $this->catalog->endOperation($lock);
        }
    }

    protected function deactivate(string $code): void
    {
        $lock = $this->catalog->beginOperation();
        try {
            $this->lifecycle->transition($code, 'inactive');
            $this->catalog->publish($code);
        } finally {
            $this->catalog->endOperation($lock);
        }
    }

    protected function assertProtocolError(string $code, callable $action): ProtocolException
    {
        try {
            $action();
            self::fail('预期协议调用失败：'.$code);
        } catch (ProtocolException $exception) {
            self::assertSame($code, $exception->errorCode, $exception->getMessage());
            return $exception;
        }
    }
}
