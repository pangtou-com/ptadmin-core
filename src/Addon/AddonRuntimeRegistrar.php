<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

final class AddonRuntimeRegistrar
{
    public function __construct(
        private Application $app,
        private AddonDiscovery $discovery,
        private AddonLifecycleStore $lifecycle,
        private string $addonsPath,
        private ?AddonTemplateDirectiveRegistry $templateDirectives = null,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
    }

    /** @return array{loaded:array<int,string>,errors:array<int,array<string,mixed>>} */
    public function register(): array
    {
        $this->registerCoreTemplateDirectives();
        $scan = $this->discovery->scan(true);
        $loaded = [];
        $errors = $scan['errors'];
        foreach ($scan['addons'] as $addon) {
            $code = (string) ($addon['code'] ?? '');
            if (($addon['state'] ?? 'active') !== 'active') {
                continue;
            }
            $directory = $this->addonsPath.'/'.$code;
            try {
                $manifest = is_array($addon['manifest'] ?? null) ? $addon['manifest'] : [];
                $this->assertCanonicalDirectories($directory);
                $this->registerMigrations($directory);
                $this->registerFunctions($directory, $manifest);
                $this->registerBootstrap($directory, $code, $manifest);
                $this->registerRoutes($directory, $manifest);
                $this->registerProvider($directory, $code, $manifest);
                $this->registerConfig($directory, $code, $manifest);
                $this->registerViewsAndTranslations($directory, $code);
                $this->registerTemplateDirectives($directory, $code, $manifest);
                $this->registerHooks($directory, $code, $manifest);
                $loaded[] = $code;
            } catch (\Throwable $exception) {
                $errorCode = $exception instanceof AddonManifestException
                    ? $exception->errorCode
                    : 'addon_runtime_registration_failed';
                try {
                    $this->lifecycle->transition($code, 'inactive', [
                        'runtime_error_code' => $errorCode,
                        'runtime_error' => $exception->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Preserve the original runtime diagnostic.
                }
                $errors[] = [
                    'code' => $code,
                    'error_code' => $errorCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }
        return ['loaded' => $loaded, 'errors' => $errors];
    }

    private function assertCanonicalDirectories(string $directory): void
    {
        $entries = scandir($directory) ?: [];
        foreach (['Resources'] as $name) {
            $legacy = $directory.'/'.strtolower($name);
            if (is_dir($legacy) && !in_array($name, $entries, true)) {
                throw new AddonManifestException("Addon directory must use {$name}/: {$legacy}", 'noncanonical_resource_path');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function registerFunctions(string $directory, array $manifest): void
    {
        $declared = $manifest['functions'] ?? null;
        if ($declared === null) {
            $default = $directory.'/functions.php';
            if (is_file($default)) require_once $default;
            return;
        }
        $paths = is_string($declared) ? [$declared] : (is_array($declared) ? $declared : []);
        foreach ($paths as $relative) {
            $relative = $this->safeRelative((string) $relative);
            if ($relative === '') throw new AddonManifestException('Addon functions declaration is invalid.', 'invalid_functions');
            $path = $directory.'/'.$relative;
            if (!is_file($path)) throw new AddonManifestException('Addon functions file is unavailable.', 'functions_unavailable');
            require_once $path;
        }
    }

    private function registerMigrations(string $directory): void
    {
        $path = $directory.'/database/migrations';
        if (!is_dir($path)) {
            return;
        }
        if ($this->app->resolved('migrator')) {
            $this->app->make('migrator')->path($path);

            return;
        }
        $this->app->afterResolving('migrator', static function ($migrator) use ($path): void {
            $migrator->path($path);
        });
    }

    /** @param array<string,mixed> $manifest */
    private function registerBootstrap(string $directory, string $code, array $manifest): void
    {
        $bootstrapValue = $manifest['bootstrap'] ?? null;
        $bootstrap = is_string($bootstrapValue) ? ['file' => $bootstrapValue] : (is_array($bootstrapValue) ? $bootstrapValue : []);
        $file = $this->safeRelative((string) ($bootstrap['file'] ?? 'Bootstrap.php'));
        if (!is_file($directory.'/'.$file) && !array_key_exists('bootstrap', $manifest)) return;
        $class = trim((string) ($bootstrap['class'] ?? ''));
        if ($class === '' && isset($manifest['namespace'])) $class = trim((string) $manifest['namespace'], "\\").'\\Bootstrap';
        if ($class === '' || $file === '') throw new AddonManifestException('Addon bootstrap declaration is invalid.', 'invalid_bootstrap');
        $path = $directory.'/'.$file;
        if (!is_file($path)) throw new AddonManifestException('Addon bootstrap file is unavailable.', 'bootstrap_unavailable');
        require_once $path;
        if (!class_exists($class)) throw new AddonManifestException('Addon bootstrap class is unavailable.', 'bootstrap_class_unavailable');
        $instance = $this->app->make($class);
        if (!$instance instanceof AddonBootstrapInterface) throw new AddonManifestException('Addon bootstrap class must implement AddonBootstrapInterface.', 'invalid_bootstrap');
        $instance->register($this->app, $code, $manifest);
    }

    /** @param array<string,mixed> $manifest */
    private function registerRoutes(string $directory, array $manifest): void
    {
        $routes = $manifest['routes'] ?? null;
        if ($routes === null) {
            $paths = glob($directory.'/Routes/*.php') ?: [];
            sort($paths, SORT_STRING);
            $routes = array_map(static fn (string $path): string => 'Routes/'.basename($path), $paths);
        }
        if (is_string($routes)) $routes = [$routes];
        if (!is_array($routes)) throw new AddonManifestException('Addon routes declaration is invalid.', 'invalid_routes');
        foreach ($routes as $route) {
            if (!is_string($route) || ($relative = $this->safeRelative($route)) === '') throw new AddonManifestException('Addon route path is invalid.', 'invalid_routes');
            $path = $directory.'/'.$relative;
            if (!is_file($path)) throw new AddonManifestException('Addon route file is unavailable.', 'routes_unavailable');
            $registered = require $path;
            if ($registered instanceof Closure) $registered($this->app);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function registerProvider(string $directory, string $code, array $manifest): void
    {
        $providerValue = $manifest['provider'] ?? ($manifest['providers'] ?? null);
        if (is_array($providerValue) && array_is_list($providerValue)) {
            foreach ($providerValue as $entry) {
                $this->registerProviderDeclaration($directory, $code, $manifest, $entry);
            }
            return;
        }
        $provider = is_string($providerValue) ? ['file' => $providerValue] : (is_array($providerValue) ? $providerValue : []);
        $this->registerProviderDeclaration($directory, $code, $manifest, $provider);
    }

    /** @param array<string,mixed> $manifest */
    private function registerProviderDeclaration(string $directory, string $code, array $manifest, mixed $declaration): void
    {
        $provider = is_string($declaration) ? ['file' => $declaration] : (is_array($declaration) ? $declaration : []);
        $file = $this->safeRelative((string) ($provider['file'] ?? 'Providers/ServiceProvider.php'));
        $class = trim((string) ($provider['class'] ?? ''));
        if ($class === '' && isset($manifest['namespace'])) $class = trim((string) $manifest['namespace'], "\\").'\\Providers\\ServiceProvider';
        if ($class === '' && !is_file($directory.'/'.$file)) return;
        if ($class === '' || $file === '') throw new AddonManifestException('Addon provider declaration is invalid.', 'invalid_provider');
        $path = $directory.'/'.$file;
        if (!is_file($path)) throw new AddonManifestException('Addon provider file is unavailable.', 'provider_unavailable');
        require_once $path;
        if (!class_exists($class)) throw new AddonManifestException('Addon provider class is unavailable.', 'provider_class_unavailable');
        if (!is_a($class, AddonProviderInterface::class, true) || !is_a($class, ServiceProvider::class, true)) {
            throw new AddonManifestException('Addon provider must implement AddonProviderInterface and extend ServiceProvider.', 'invalid_provider');
        }
        $this->app->register($class);
    }

    /** @param array<string,mixed> $manifest */
    private function registerConfig(string $directory, string $code, array $manifest): void
    {
        $declared = $manifest['config'] ?? 'Config/config.php';
        $relative = is_string($declared) ? $this->safeRelative($declared) : '';
        if ($relative === '') throw new AddonManifestException('Addon config declaration is invalid.', 'invalid_config');
        $path = $directory.'/'.$relative;
        if (!is_file($path)) return;
        $values = require $path;
        if (!is_array($values)) throw new AddonManifestException('Addon config.php must return an array.', 'invalid_config');
        config(['addon.'.strtolower($code) => $values]);
    }

    private function registerViewsAndTranslations(string $directory, string $code): void
    {
        $views = $directory.'/Resources/Views';
        if (is_dir($views)) {
            $callback = static function ($factory) use ($views, $code): void { $factory->addNamespace(strtolower($code), $views); };
            $this->app->resolved('view') ? $callback($this->app->make('view')) : $this->app->afterResolving('view', $callback);
        }
        $lang = $directory.'/Resources/Lang';
        if (is_dir($lang)) {
            $callback = static function ($translator) use ($lang, $code): void { $translator->addNamespace(strtolower($code), $lang); };
            $this->app->resolved('translator') ? $callback($this->app->make('translator')) : $this->app->afterResolving('translator', $callback);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function registerTemplateDirectives(string $directory, string $code, array $manifest): void
    {
        $registry = $this->templateDirectives ??= new AddonTemplateDirectiveRegistry();
        foreach ($registry->all() as $definition) {
            if ($definition->addonCode() === $code) {
                return;
            }
        }
        $path = $directory.'/Directives';
        if (!is_dir($path)) return;
        $staging = new AddonTemplateDirectiveRegistry();
        $files = glob($path.'/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $before = get_declared_classes();
            require_once $file;
            $directiveClasses = [];
            $declared = array_diff(get_declared_classes(), $before);
            foreach (get_declared_classes() as $class) {
                if (in_array($class, $declared, true)) {
                    continue;
                }
                try {
                    $reflection = new \ReflectionClass($class);
                } catch (\ReflectionException) {
                    continue;
                }
                if ($reflection->getFileName() !== $file) {
                    continue;
                }
                $declared[] = $class;
            }
            foreach ($declared as $class) if (is_a($class, AddonTemplateDirectiveInterface::class, true)) $directiveClasses[] = $class;
            if ($directiveClasses === []) throw new AddonManifestException('Addon directive file must declare AddonTemplateDirectiveInterface.', 'invalid_directives');
            $registration = $staging->forAddon($code);
            foreach ($directiveClasses as $class) $this->app->make($class)->register($registration, $manifest);
            $registration->commit();
        }
        $registry->commitBatch(array_values($staging->all()));
    }

    private function registerCoreTemplateDirectives(): void
    {
        $registry = $this->templateDirectives ??= new AddonTemplateDirectiveRegistry();
        if ($registry->get('seo') !== null) return;
        $core = new AddonBuiltinTemplateDirectives();
        $registration = $registry->forAddon('core');
        $core->register($registration);
        $registration->commit();
    }

    /** @param array<string,mixed> $manifest */
    private function registerHooks(string $directory, string $code, array $manifest): void
    {
        $hooks = $manifest['hooks'] ?? [];
        if (!is_array($hooks)) {
            throw new AddonManifestException('Addon hooks declaration is invalid.', 'invalid_hooks');
        }
        if ($hooks === []) {
            return;
        }
        $events = $this->app->make('events');
        if (!$events instanceof Dispatcher) {
            throw new AddonManifestException('Addon event dispatcher is unavailable.', 'events_unavailable');
        }
        foreach ($hooks as $hook) {
            if (is_string($hook)) {
                $hook = ['file' => $hook];
            }
            if (!is_array($hook)) {
                throw new AddonManifestException('Addon hook declaration is invalid.', 'invalid_hooks');
            }
            $file = $this->safeRelative((string) ($hook['file'] ?? ''));
            $class = trim((string) ($hook['class'] ?? ''));
            if ($file === '' || $class === '') {
                throw new AddonManifestException('Addon hook declaration is invalid.', 'invalid_hooks');
            }
            $path = $directory.'/'.$file;
            if (!is_file($path)) {
                throw new AddonManifestException('Addon hook file is unavailable.', 'hooks_unavailable');
            }
            require_once $path;
            if (!class_exists($class)) {
                throw new AddonManifestException('Addon hook class is unavailable.', 'hook_class_unavailable');
            }
            $instance = $this->app->make($class);
            if (!$instance instanceof AddonHookInterface) {
                throw new AddonManifestException('Addon hook class must implement AddonHookInterface.', 'invalid_hooks');
            }
            $instance->register($events, $code, $manifest);
        }
    }

    private function safeRelative(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $path);
        if ($path === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) return '';
        return $path;
    }
}
