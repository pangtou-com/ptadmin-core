<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use Illuminate\Foundation\Application;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonBootstrapInterface;
use PTAdmin\Addon\AddonDiscovery;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonManifestVerifier;
use PTAdmin\Addon\AddonRuntimeRegistrar;

final class AddonRuntimeRegistrarTest extends TestCase
{
    private string $root;
    private string $lifecycle;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-runtime-'.bin2hex(random_bytes(5));
        $this->lifecycle = sys_get_temp_dir().'/ptadmin-addon-runtime-state-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/runtime-addon/Routes', 0700, true);
        mkdir($this->root.'/runtime-addon/Hooks', 0700, true);
        mkdir($this->root.'/runtime-addon/database/migrations', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Bootstrap.php', <<<'PHP'
<?php
namespace RuntimeAddon;
use Illuminate\Contracts\Foundation\Application;
use PTAdmin\Addon\AddonBootstrapInterface;
final class Bootstrap implements AddonBootstrapInterface
{
    public function register(Application $app, string $code, array $manifest): void
    {
        $app->instance('runtime-addon.loaded', $code);
    }
}
PHP);
        file_put_contents($this->root.'/runtime-addon/Routes/admin.php', <<<'PHP'
<?php
return static function ($app): void {
    $app->instance('runtime-addon.routes', true);
};
PHP);
        file_put_contents($this->root.'/runtime-addon/Hooks/RuntimeHook.php', <<<'PHP'
<?php
namespace RuntimeAddon;

use Illuminate\Contracts\Events\Dispatcher;
use PTAdmin\Addon\AddonHookInterface;

final class RuntimeHook implements AddonHookInterface
{
    public static bool $called = false;

    public function register(Dispatcher $events, string $code, array $manifest): void
    {
        $events->listen('runtime-addon.test', static function (): void {
            self::$called = true;
        });
    }
}
PHP);
        file_put_contents($this->root.'/runtime-addon/manifest.json', json_encode([
            'code' => 'runtime-addon', 'version' => '1.0.0',
            'bootstrap' => ['file' => 'Bootstrap.php', 'class' => 'RuntimeAddon\\Bootstrap'],
            'routes' => ['Routes/admin.php'],
            'hooks' => [['file' => 'Hooks/RuntimeHook.php', 'class' => 'RuntimeAddon\\RuntimeHook']],
            'files' => [
                'Bootstrap.php',
                'Hooks/RuntimeHook.php',
                'Routes/admin.php',
                'Routes/.keep',
                'database/migrations/2026_09_17_000001_demo.php',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/runtime-addon/Routes/.keep', '');
        file_put_contents($this->root.'/runtime-addon/database/migrations/2026_09_17_000001_demo.php', '<?php return null;');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        $this->remove($this->lifecycle);
    }

    /** @runInSeparateProcess */
    public function testOnlyExplicitBootstrapAndRoutesAreRegistered(): void
    {
        $app = new Application($this->root);
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $migrator = new class {
            /** @var list<string> */
            public array $paths = [];

            public function path(string $path): void
            {
                $this->paths[] = $path;
            }
        };
        $app->instance('migrator', $migrator);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
        );

        self::assertSame(['runtime-addon'], $registrar->register()['loaded']);
        self::assertSame('runtime-addon', $app->make('runtime-addon.loaded'));
        self::assertTrue($app->make('runtime-addon.routes'));
        self::assertSame([$this->root.'/runtime-addon/database/migrations'], $migrator->paths);
        $events->dispatch('runtime-addon.test');
        self::assertTrue(\RuntimeAddon\RuntimeHook::$called);
    }

    /** @runInSeparateProcess */
    public function testInvalidBootstrapMakesAddonInactiveWithoutBlockingScan(): void
    {
        file_put_contents($this->root.'/runtime-addon/manifest.json', json_encode([
            'code' => 'runtime-addon', 'version' => '1.0.0',
            'bootstrap' => ['file' => 'Bootstrap.php', 'class' => 'Missing\\Bootstrap'],
        ], JSON_THROW_ON_ERROR));
        $app = new Application($this->root);
        $registrar = new AddonRuntimeRegistrar($app, new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false), new AddonLifecycleStore($this->lifecycle), $this->root);
        $result = $registrar->register();

        self::assertSame([], $result['loaded']);
        self::assertSame('bootstrap_class_unavailable', $result['errors'][0]['error_code']);
        $state = (new AddonLifecycleStore($this->lifecycle))->read('runtime-addon');
        self::assertSame('inactive', $state['state']);
        self::assertSame('bootstrap_class_unavailable', $state['runtime_error_code']);
        $diagnostic = (new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false))->scan();
        self::assertSame('inactive', $diagnostic['addons'][0]['state']);
        self::assertSame('bootstrap_class_unavailable', $diagnostic['addons'][0]['diagnostic_code']);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) continue;
            $entry = $path.'/'.$name;
            is_dir($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
