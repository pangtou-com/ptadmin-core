<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Runtime;

use Illuminate\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Runtime\Contracts\BootstrapInterface;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Directive\DirectiveRegistration;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Runtime\RuntimeRegistrar;
use PTAdmin\Addon\Runtime\TemplateDirectiveLoader;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

final class RuntimeRegistrarTest extends TestCase
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
use PTAdmin\Addon\Runtime\Contracts\BootstrapInterface;
final class Bootstrap implements BootstrapInterface
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
use PTAdmin\Addon\Runtime\Contracts\HookInterface;

final class RuntimeHook implements HookInterface
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
            'namespace' => 'RuntimeAddon',
            'routes' => ['Routes/admin.php'],
            'hooks' => [['file' => 'Hooks/RuntimeHook.php', 'class' => 'RuntimeAddon\\RuntimeHook']],
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
    public function testDefaultBootstrapAndRoutesAreRegistered(): void
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
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
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
namespace RuntimeAddon\Runtime;

use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;

final class Registrar implements RuntimeRegistrarInterface
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
        $this->writeRuntimeManifest();
        $app = new Application($this->root);
        $registry = new RuntimeRegistry();
        $app->instance(RuntimeRegistry::class, $registry);
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
        );

        self::assertSame(['runtime-addon'], $registrar->register()['loaded']);
        $binding = $registry->build()->toArray()['resources']['runtime.posts']['hooks']['create.before'][0];

        self::assertEqualsCanonicalizing(['type' => 'addon', 'code' => 'runtime-addon'], $binding['owner']);
    }

    /** @runInSeparateProcess */
    public function testRuntimeOwnerUsesVerifiedManifestCodeInsteadOfDirectoryName(): void
    {
        rename($this->root.'/runtime-addon', $this->root.'/RuntimeAddon');
        mkdir($this->root.'/RuntimeAddon/Runtime', 0700, true);
        file_put_contents($this->root.'/RuntimeAddon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon\Runtime;
use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements RuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        $runtime->resource('runtime.posts')->hook('create.before', Handler::class, 'before');
    }
}
final class Handler { public function before(): void {} }
PHP);
        $manifestPath = $this->root.'/RuntimeAddon/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $registry = new RuntimeRegistry();
        $app = new Application($this->root);
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
            null,
            $registry,
        );

        self::assertSame(['RuntimeAddon'], $registrar->register()['loaded']);
        $binding = $registry->build()->toArray()['resources']['runtime.posts']['hooks']['create.before'][0];
        self::assertSame(['code' => 'runtime-addon', 'type' => 'addon'], $binding['owner']);
    }

    /** @runInSeparateProcess */
    public function testUserDisabledAddonDoesNotLoadRuntimeManifest(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon\Runtime;
use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements RuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        throw new \RuntimeException('runtime should not load');
    }
}
PHP);
        $this->writeRuntimeManifest();
        $lifecycle = new LifecycleStore($this->lifecycle);
        (new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle, false))->scan();
        $lifecycle->transition('runtime-addon', 'inactive', ['disabled_by_user' => true]);
        $registry = new RuntimeRegistry();
        $app = new Application($this->root);
        $app->instance(RuntimeRegistry::class, $registry);
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle, false),
            $lifecycle,
            $this->root,
        );

        self::assertSame([], $registrar->register()['loaded']);
        self::assertSame([], $registry->build()->toArray()['resources']);
        self::assertFalse(class_exists('RuntimeAddon\\Runtime\\Registrar', false));
    }

    /** @runInSeparateProcess */
    public function testRuntimeRegistrationFailureMakesAddonInactiveWithDiagnostic(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon\Runtime;
use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements RuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void
    {
        throw new \RuntimeException('runtime registration failed');
    }
}
PHP);
        $this->writeRuntimeManifest();
        $lifecycle = new LifecycleStore($this->lifecycle);
        $app = new Application($this->root);
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle, false),
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
    public function testRuntimeManifestRequiresTheSharedRegistry(): void
    {
        mkdir($this->root.'/runtime-addon/Runtime', 0700, true);
        file_put_contents($this->root.'/runtime-addon/Runtime/Registrar.php', <<<'PHP'
<?php
namespace RuntimeAddon\Runtime;
use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeContributor;
final class Registrar implements RuntimeRegistrarInterface
{
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void {}
}
PHP);
        $this->writeRuntimeManifest();
        $lifecycle = new LifecycleStore($this->lifecycle);
        $registrar = new RuntimeRegistrar(
            new Application($this->root),
            new AddonCatalog($this->root, new ManifestVerifier(), $lifecycle, false),
            $lifecycle,
            $this->root,
        );

        $result = $registrar->register();

        self::assertSame([], $result['loaded']);
        self::assertSame('runtime_registry_unavailable', $result['errors'][0]['error_code']);
        self::assertSame('inactive', $lifecycle->read('runtime-addon')['state']);
    }

    /** @runInSeparateProcess */
    public function testInvalidBootstrapMakesAddonInactiveWithoutBlockingScan(): void
    {
        file_put_contents($this->root.'/runtime-addon/manifest.json', json_encode([
            'code' => 'runtime-addon', 'version' => '1.0.0', 'namespace' => 'Missing',
        ], JSON_THROW_ON_ERROR));
        $app = new Application($this->root);
        $registrar = new RuntimeRegistrar($app, new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false), new LifecycleStore($this->lifecycle), $this->root);
        $result = $registrar->register();

        self::assertSame([], $result['loaded']);
        self::assertSame('bootstrap_class_unavailable', $result['errors'][0]['error_code']);
        $state = (new LifecycleStore($this->lifecycle))->read('runtime-addon');
        self::assertSame('inactive', $state['state']);
        self::assertSame('bootstrap_class_unavailable', $state['runtime_error_code']);
        $diagnostic = (new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false))->scan();
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
use PTAdmin\Addon\Runtime\Contracts\BootstrapInterface;
final class Bootstrap implements BootstrapInterface
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
use PTAdmin\Addon\Runtime\Contracts\ProviderInterface;
final class ServiceProvider extends LaravelServiceProvider implements ProviderInterface
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
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
            new TemplateDirectiveLoader($app, new DirectiveRegistry()),
        );

        $result = $registrar->register();
        self::assertSame(['demo'], $result['loaded']);
        self::assertTrue(class_exists('DemoAddon\\Functions'));
        self::assertTrue($app->make('demo.bootstrap'));
        self::assertTrue($app->make('demo.route'));
        self::assertTrue($app->make('demo.provider'));
    }

    /** @runInSeparateProcess */
    public function testProviderWithoutProviderInterfaceIsRejected(): void
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
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertSame('invalid_provider', $result['errors'][0]['error_code']);
    }

    /** @runInSeparateProcess */
    public function testNamespaceDoesNotRequireAnImplicitProviderFile(): void
    {
        $this->remove($this->root.'/runtime-addon');
        $directory = $this->root.'/demo';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'demo',
            'version' => '1.0.0',
            'namespace' => 'DemoAddon',
        ], JSON_THROW_ON_ERROR));

        $app = new Application($this->root);
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();
        self::assertSame(['demo'], $result['loaded']);
        self::assertSame([], $result['errors']);
    }

    /** @runInSeparateProcess */
    public function testTemplateDirectiveWithoutTemplateDirectiveProviderInterfaceIsRejected(): void
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
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
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
use PTAdmin\Addon\Runtime\Contracts\TemplateDirectiveProviderInterface;
use PTAdmin\Template\Directive\DirectiveRegistration;
final class DemoDirective implements TemplateDirectiveProviderInterface
{
    public function register(DirectiveRegistration \$registry, array \$manifest): void
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
        $registry = new DirectiveRegistry();
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
            new TemplateDirectiveLoader($app, $registry),
        );

        $result = $registrar->register();
        self::assertSame(['first', 'second'], $result['loaded']);
        self::assertSame('first', $registry->all()['first_demo']->namespace());
        self::assertSame('second', $registry->all()['second_demo']->namespace());
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
        $registrar = new RuntimeRegistrar(
            $app,
            new AddonCatalog($this->root, new ManifestVerifier(), new LifecycleStore($this->lifecycle), false),
            new LifecycleStore($this->lifecycle),
            $this->root,
        );

        $result = $registrar->register();

        self::assertSame([], $result['loaded']);
        self::assertSame('noncanonical_resource_path', $result['errors'][0]['error_code']);
        self::assertSame('inactive', (new LifecycleStore($this->lifecycle))->read('demo')['state']);
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

    private function writeRuntimeManifest(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/runtime-addon/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['runtime'], $manifest['files']);
        file_put_contents($this->root.'/runtime-addon/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
