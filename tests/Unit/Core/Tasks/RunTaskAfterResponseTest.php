<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Tasks;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Tasks\RunTaskAfterResponse;

final class RunTaskAfterResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Artisan::clearResolvedInstances();
        Artisan::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    public function testCommandOutputIsNotWrittenToTheOuterBuffer(): void
    {
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('call')->once()->andReturnUsing(static function (): int {
            echo 'Task completed.';
            return 0;
        });
        $container = new Container();
        $container->instance(Kernel::class, $kernel);
        Artisan::setFacadeApplication($container);

        ob_start();
        (new RunTaskAfterResponse('task-id'))->handle();

        self::assertSame('', (string) ob_get_clean());
    }
}
