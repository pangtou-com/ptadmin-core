<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Events\Dispatcher;

final class AddonRuntimeRegistrar
{
    public function __construct(
        private Application $app,
        private AddonDiscovery $discovery,
        private AddonLifecycleStore $lifecycle,
        private string $addonsPath,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
    }

    /** @return array{loaded:array<int,string>,errors:array<int,array<string,mixed>>} */
    public function register(): array
    {
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
                $this->registerMigrations($directory);
                $this->registerBootstrap($directory, $code, $manifest);
                $this->registerRoutes($directory, $manifest);
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
        $bootstrap = $manifest['bootstrap'] ?? null;
        if (!is_array($bootstrap)) return;
        $file = $this->safeRelative((string) ($bootstrap['file'] ?? 'Bootstrap.php'));
        $class = trim((string) ($bootstrap['class'] ?? ''));
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
        $routes = $manifest['routes'] ?? [];
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
