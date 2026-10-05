<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, ProtocolException};

abstract class ProtocolTestCase extends TestCase
{
    protected string $temporary;
    protected LifecycleStore $lifecycle;
    protected Container $container;
    protected AddonAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/ptadmin-protocol-'.bin2hex(random_bytes(8));
        $this->lifecycle = new LifecycleStore($this->temporary.'/lifecycle');
        $this->container = new Container();
        $this->availability = new AddonAvailability($this->lifecycle);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temporary);
        parent::tearDown();
    }

    protected function activate(string $code): void
    {
        $this->lifecycle->transition($code, 'active');
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
