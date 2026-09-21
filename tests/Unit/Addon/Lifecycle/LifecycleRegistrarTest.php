<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Lifecycle;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Lifecycle\LifecycleContext;
use PTAdmin\Addon\Lifecycle\LifecycleRegistrar;

final class LifecycleRegistrarTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-lifecycle-handler-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/demo/Lifecycle', 0700, true);
        file_put_contents($this->root.'/demo/Lifecycle/Handler.php', <<<'PHP'
<?php
namespace DemoLifecycle\Lifecycle;

use PTAdmin\Addon\Lifecycle\Contracts\LifecycleInterface;
use PTAdmin\Addon\Lifecycle\LifecycleContext;

final class Handler implements LifecycleInterface
{
    public function install(LifecycleContext $context): void { $GLOBALS['ptadmin_lifecycle_events'][] = ['install', $context->code]; }
    public function uninstall(LifecycleContext $context): void { $GLOBALS['ptadmin_lifecycle_events'][] = ['uninstall', $context->code]; }
    public function enable(LifecycleContext $context): void { $GLOBALS['ptadmin_lifecycle_events'][] = ['enable', $context->code]; }
    public function disable(LifecycleContext $context): void { $GLOBALS['ptadmin_lifecycle_events'][] = ['disable', $context->code]; }
}
PHP);
        $GLOBALS['ptadmin_lifecycle_events'] = [];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        unset($GLOBALS['ptadmin_lifecycle_events']);
    }

    public function testDefaultLifecycleHandlerReceivesAllEvents(): void
    {
        $registrar = new LifecycleRegistrar(new Application($this->root));
        $manifest = ['code' => 'demo', 'version' => '1.0.0', 'namespace' => 'DemoLifecycle'];

        foreach (['install', 'enable', 'disable', 'uninstall'] as $event) {
            $registrar->invoke($event, $this->root.'/demo', 'demo', $manifest);
        }

        self::assertSame([
            ['install', 'demo'],
            ['enable', 'demo'],
            ['disable', 'demo'],
            ['uninstall', 'demo'],
        ], $GLOBALS['ptadmin_lifecycle_events']);
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
