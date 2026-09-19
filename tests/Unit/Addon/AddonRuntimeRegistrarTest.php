<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use Illuminate\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonBootstrapInterface;
use PTAdmin\Addon\AddonTemplateDirectiveRegistry;
use PTAdmin\Addon\AddonTemplateDirectiveRegistration;
use PTAdmin\Addon\AddonDiscovery;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonManifestVerifier;
use PTAdmin\Addon\AddonRuntimeRegistrar;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

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
    $app->make('router')->middleware('ptadmin.permission:runtime')->get('/runtime-addon', static fn (): string => 'ok');
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
        $app->instance('router', new Router($events, $app));
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
        $route = null;
        foreach ($app->make('router')->getRoutes() as $candidate) {
            if ($candidate->uri() === 'runtime-addon') {
                $route = $candidate;
                break;
            }
        }
        self::assertNotNull($route);
        self::assertContains('ptadmin.session', $route->gatherMiddleware());
        self::assertContains('ptadmin.permission:runtime', $route->gatherMiddleware());
        self::assertSame([$this->root.'/runtime-addon/database/migrations'], $migrator->paths);
        $events->dispatch('runtime-addon.test');
        self::assertTrue(\RuntimeAddon\RuntimeHook::$called);
    }

    /** @runInSeparateProcess */
    public function testActiveAddonRuntimeManifestRegistersWithVerifiedAddonOwner(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon;

use PTAdmin\Addon\AddonRuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;

final class Registrar implements AddonRuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        $runtime->resource('runtime.posts')->hook('create.before', Handler::class, 'before');
    }
}

final class Handler
{
    public function before(): void {}
}
PHP);
        $this->writeRuntimeManifest(['file' => 'Runtime/Registrar.php', 'class' => 'RuntimeAddon\\Registrar']);
        $app = new Application($this->root);
        $registry = new RuntimeRegistry();
        $app->instance(RuntimeRegistry::class, $registry);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
        );

        self::assertSame(['runtime-addon'], $registrar->register()['loaded']);
        $binding = $registry->build()->toArray()['resources']['runtime.posts']['hooks']['create.before'][0];

        self::assertEqualsCanonicalizing(['type' => 'addon', 'code' => 'runtime-addon'], $binding['owner']);
    }

    /** @runInSeparateProcess */
    public function testUserDisabledAddonDoesNotLoadRuntimeManifest(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon;
use PTAdmin\Addon\AddonRuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements AddonRuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        throw new \RuntimeException('runtime should not load');
    }
}
PHP);
        $this->writeRuntimeManifest(['file' => 'Runtime/Registrar.php', 'class' => 'RuntimeAddon\\Registrar']);
        $lifecycle = new AddonLifecycleStore($this->lifecycle);
        (new AddonDiscovery($this->root, new AddonManifestVerifier(), $lifecycle, false))->scan();
        $lifecycle->transition('runtime-addon', 'inactive', ['disabled_by_user' => true]);
        $registry = new RuntimeRegistry();
        $app = new Application($this->root);
        $app->instance(RuntimeRegistry::class, $registry);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), $lifecycle, false),
            $lifecycle,
            $this->root,
        );

        self::assertSame([], $registrar->register()['loaded']);
        self::assertSame([], $registry->build()->toArray()['resources']);
        self::assertFalse(class_exists('RuntimeAddon\\Registrar', false));
    }

    /** @runInSeparateProcess */
    public function testRuntimeRegistrationFailureMakesAddonInactiveWithDiagnostic(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon;
use PTAdmin\Addon\AddonRuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements AddonRuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        throw new \RuntimeException('runtime registration failed');
    }
}
PHP);
        $this->writeRuntimeManifest(['file' => 'Runtime/Registrar.php', 'class' => 'RuntimeAddon\\Registrar']);
        $lifecycle = new AddonLifecycleStore($this->lifecycle);
        $app = new Application($this->root);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), $lifecycle, false),
            $lifecycle,
            $this->root,
            null,
            new RuntimeRegistry(),
        );

        $result = $registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertSame('addon_runtime_registration_failed', $result['errors'][0]['error_code']);
        $state = $lifecycle->read('runtime-addon');
        self::assertSame('inactive', $state['state']);
        self::assertSame('addon_runtime_registration_failed', $state['runtime_error_code']);
        self::assertSame('runtime registration failed', $state['runtime_error']);
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

    /** @runInSeparateProcess */
    public function testDefaultEntrypointsAndProviderInterfaceAreLoaded(): void
    {
        $this->remove($this->root.'/runtime-addon');
        $directory = $this->root.'/demo';
        mkdir($directory.'/Routes', 0700, true);
        mkdir($directory.'/Providers', 0700, true);
        file_put_contents($directory.'/functions.php', "<?php require_once __DIR__.'/Functions.php'; \\DemoAddon\\Functions::load();");
        file_put_contents($directory.'/Bootstrap.php', <<<'PHP'
<?php
namespace DemoAddon;
use Illuminate\Contracts\Foundation\Application;
use PTAdmin\Addon\AddonBootstrapInterface;
final class Bootstrap implements AddonBootstrapInterface
{
    public function register(Application $app, string $code, array $manifest): void
    {
        $app->instance('demo.bootstrap', true);
    }
}
PHP);
        file_put_contents($directory.'/Functions.php', <<<'PHP'
<?php
namespace DemoAddon;
final class Functions
{
    public static function load(): void
    {
        $GLOBALS['ptadmin_demo_functions_loaded'] = true;
    }
}
PHP);
        file_put_contents($directory.'/Routes/demo.php', <<<'PHP'
<?php
return static function ($app): void {
    $app->instance('demo.route', true);
};
PHP);
        file_put_contents($directory.'/Providers/ServiceProvider.php', <<<'PHP'
<?php
namespace DemoAddon\Providers;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use PTAdmin\Addon\AddonProviderInterface;
final class ServiceProvider extends LaravelServiceProvider implements AddonProviderInterface
{
    public function register(): void
    {
        $this->app->instance('demo.provider', true);
    }
}
PHP);
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'demo',
            'version' => '1.0.0',
            'namespace' => 'DemoAddon',
        ], JSON_THROW_ON_ERROR));

        $app = new Application($this->root);
        $app->instance('events', new Dispatcher($app));
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
            new AddonTemplateDirectiveRegistry(),
        );

        $result = $registrar->register();
        self::assertSame(['demo'], $result['loaded']);
        self::assertTrue(class_exists('DemoAddon\\Functions'));
        self::assertTrue($app->make('demo.bootstrap'));
        self::assertTrue($app->make('demo.route'));
        self::assertTrue($app->make('demo.provider'));
    }

    /** @runInSeparateProcess */
    public function testProviderWithoutAddonProviderInterfaceIsRejected(): void
    {
        $this->remove($this->root.'/runtime-addon');
        $directory = $this->root.'/demo';
        mkdir($directory.'/Providers', 0700, true);
        file_put_contents($directory.'/Providers/ServiceProvider.php', <<<'PHP'
<?php
namespace InvalidDemo\Providers;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
final class ServiceProvider extends LaravelServiceProvider {}
PHP);
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'demo',
            'version' => '1.0.0',
            'namespace' => 'InvalidDemo',
        ], JSON_THROW_ON_ERROR));
        $app = new Application($this->root);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertSame('invalid_provider', $result['errors'][0]['error_code']);
    }

    /** @runInSeparateProcess */
    public function testTemplateDirectiveWithoutAddonTemplateDirectiveInterfaceIsRejected(): void
    {
        $this->remove($this->root.'/runtime-addon');
        $directory = $this->root.'/demo';
        mkdir($directory.'/Directives', 0700, true);
        file_put_contents($directory.'/Directives/DemoDirective.php', <<<'PHP'
<?php
namespace InvalidDemo;
final class DemoDirective {}
PHP);
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'demo',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        $app = new Application($this->root);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertSame('invalid_directives', $result['errors'][0]['error_code']);
    }

    /** @runInSeparateProcess */
    public function testTemplateDirectiveRegistersAndDuplicateAliasesAreRejectedAtInvocationAcrossAddons(): void
    {
        $this->remove($this->root.'/runtime-addon');
        foreach (['first', 'second'] as $code) {
            $directory = $this->root.'/'.$code.'/Directives';
            mkdir($directory, 0700, true);
            file_put_contents($directory.'/DemoDirective.php', <<<PHP
<?php
namespace {$code}Addon;
use PTAdmin\Addon\AddonTemplateDirectiveInterface;
use PTAdmin\Addon\AddonTemplateDirectiveRegistration;
final class DemoDirective implements AddonTemplateDirectiveInterface
{
    public function register(AddonTemplateDirectiveRegistration \$registry, array \$manifest): void
    {
        \$registry->define('demo')->output([\$this, 'render'])->alias('shared')->commit();
    }

    public function render(array \$arguments = [], array \$context = []): string { return 'ok'; }
}
PHP);
            file_put_contents($this->root.'/'.$code.'/manifest.json', json_encode([
                'code' => $code,
                'version' => '1.0.0',
            ], JSON_THROW_ON_ERROR));
        }

        $app = new Application($this->root);
        $registry = new AddonTemplateDirectiveRegistry();
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
            $registry,
        );

        $result = $registrar->register();
        self::assertSame(['first', 'second'], $result['loaded']);
        self::assertSame('first', $registry->all()['first_demo']->addonCode());
        self::assertSame('second', $registry->all()['second_demo']->addonCode());
        self::assertCount(0, $result['errors']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first_demo, second_demo');
        $registry->get('shared');
    }

    /** @runInSeparateProcess */
    public function testLowercaseResourcesDirectoryIsRejected(): void
    {
        $this->remove($this->root.'/runtime-addon');
        $directory = $this->root.'/demo';
        mkdir($directory.'/resources', 0700, true);
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'demo',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        $app = new Application($this->root);
        $registrar = new AddonRuntimeRegistrar(
            $app,
            new AddonDiscovery($this->root, new AddonManifestVerifier(), new AddonLifecycleStore($this->lifecycle), false),
            new AddonLifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();

        self::assertSame([], $result['loaded']);
        self::assertSame('noncanonical_resource_path', $result['errors'][0]['error_code']);
        self::assertSame('inactive', (new AddonLifecycleStore($this->lifecycle))->read('demo')['state']);
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

    /** @param array{file:string,class:string} $runtime */
    private function writeRuntimeManifest(array $runtime): void
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/runtime-addon/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['runtime'] = $runtime;
        $manifest['files'][] = 'Runtime/Registrar.php';
        file_put_contents($this->root.'/runtime-addon/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
