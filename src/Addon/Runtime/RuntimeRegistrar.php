<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\AddonNamespace;
use PTAdmin\Addon\Runtime\Contracts\BootstrapInterface;
use PTAdmin\Addon\Runtime\Contracts\CapabilityProviderInterface;
use PTAdmin\Addon\Runtime\Contracts\HookInterface;
use PTAdmin\Addon\Runtime\Contracts\ProviderInterface;
use PTAdmin\Addon\Runtime\Contracts\RuntimeRegistrarInterface;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Template\Directive\DirectiveRegistry;

/**
 * 将 active 插件接入当前 Laravel 应用生命周期。
 *
 * 入口按固定顺序注册 migration、函数、Bootstrap、路由、Provider、配置、视图语言、模板指令、Hook 和
 * Schema Runtime 贡献，最后调用 Provider 的能力注册入口。单个插件失败时会被标记为 inactive 并记录诊断，
 * 本次能力注册不生效，但不会阻断核心或其他插件启动。
 */
final class RuntimeRegistrar
{
    /** @var array<string, mixed>|null 同一次启动复用迁移阶段的发现结果。 */
    private ?array $startupScan = null;

    public function __construct(
        private Application $app,
        private AddonCatalog $discovery,
        private LifecycleStore $lifecycle,
        private string $addonsPath,
        private ?TemplateDirectiveLoader $templateDirectives = null,
        private ?RuntimeRegistry $runtimeRegistry = null,
        private ?AddonAutoloader $autoloader = null,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->autoloader ??= new AddonAutoloader();
    }

    /**
     * 扫描并注册所有可运行插件，返回成功列表和逐插件错误。
     *
     * @return array{loaded:array<int,string>,errors:array<int,array<string,mixed>>}
     */
    public function register(): array
    {
        $scan = $this->startupScan ?? $this->prepareAutoload($this->discovery->scan(true));
        $this->startupScan = null;
        $loaded = [];
        $errors = $scan['errors'];
        foreach ($scan['addons'] as $addon) {
            $code = strtolower((string) ($addon['code'] ?? ''));
            if (($addon['state'] ?? 'active') !== 'active') {
                continue;
            }
            $directory = $this->addonsPath.'/'.$code;
            try {
                $manifest = is_array($addon['manifest'] ?? null) ? $addon['manifest'] : [];
                $verifiedCode = (string) ($manifest['code'] ?? '');
                $this->registerMigrationPath($directory);
                $this->registerFunctions($directory, $manifest);
                $this->registerBootstrap($directory, $verifiedCode, $manifest);
                $this->registerRoutes($directory, $manifest);
                $providers = $this->registerProvider($directory, $verifiedCode, $manifest);
                $this->registerConfig($directory, $verifiedCode, $manifest);
                $this->registerViewsAndTranslations($directory, $verifiedCode);
                $this->templateDirectiveLoader()->register($directory, $verifiedCode, $manifest);
                $this->registerHooks($directory, $verifiedCode, $manifest);
                $registry = $this->runtimeRegistry
                    ?? ($this->app->bound(RuntimeRegistry::class) ? $this->app->make(RuntimeRegistry::class) : null);
                if ($this->runtimeDeclaration($directory, $verifiedCode, $manifest) !== null) {
                    if (!$registry instanceof RuntimeRegistry) {
                        throw new AddonException('Runtime registry is unavailable.', 'runtime_registry_unavailable');
                    }
                    $this->contributeRuntime($directory, $verifiedCode, $manifest, $registry);
                }
                $capabilityProviders = array_filter($providers, static fn (ServiceProvider $provider): bool => $provider instanceof CapabilityProviderInterface);
                if ($capabilityProviders !== []) {
                    if (!$this->app->bound(AddonRegistration::class)) {
                        $this->app->singleton(AddonRegistration::class);
                    }
                    $this->app->make(AddonRegistration::class)->run($verifiedCode, static function () use ($capabilityProviders, $verifiedCode): void {
                        foreach ($capabilityProviders as $provider) {
                            $provider->registerCapabilities($verifiedCode);
                        }
                    });
                }
                $loaded[] = $code;
            } catch (\Throwable $exception) {
                $errorCode = $exception instanceof AddonException
                    ? $exception->errorCode
                    : 'addon_runtime_registration_failed';
                try {
                    $this->lifecycle->transition($code, 'inactive', [
                        'runtime_error_code' => $errorCode,
                        'runtime_error' => $exception->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // 生命周期诊断写入失败时仍保留最初的 Runtime 异常，避免次生错误掩盖插件故障原因。
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

    /** Register active addon migration paths before the application's migration run. */
    public function registerMigrations(): void
    {
        $scan = $this->startupScan = $this->prepareAutoload($this->discovery->scan(true));
        foreach ($scan['addons'] as $addon) {
            if (($addon['state'] ?? 'active') !== 'active') {
                continue;
            }
            $code = strtolower((string) ($addon['code'] ?? ''));
            if ($code !== '') {
                $this->registerMigrationPath($this->addonsPath.'/'.$code);
            }
        }
    }

    /**
     * 把单个插件的 Schema Runtime 声明写入指定 Registry，Owner 始终绑定为 addon/{code}。
     *
     * 生命周期校验会传入候选 Registry 进行预检，应用启动则传入全局 Registry，因此这里不能直接构建快照。
     *
     * @param array<string,mixed> $manifest
     */
    public function contributeRuntime(string $directory, string $code, array $manifest, RuntimeRegistry $registry): void
    {
        $this->autoloader->register($directory, $manifest);
        $runtime = $this->runtimeDeclaration($directory, $code, $manifest);
        if ($runtime === null) {
            return;
        }
        $file = $runtime['file'];
        $class = $runtime['class'];
        $path = $directory.'/'.$file;
        if (!is_file($path)) {
            throw new AddonException('Addon runtime file is unavailable.', 'runtime_unavailable');
        }
        if (!class_exists($class, false)) {
            require_once $path;
        }
        if (!class_exists($class, false) || !is_a($class, RuntimeRegistrarInterface::class, true)) {
            throw new AddonException('Addon runtime class is invalid.', 'runtime_class_invalid');
        }
        $this->app->make($class)->registerRuntime($registry->forOwner('addon', $code), $manifest);
    }

    /**
     * 资源同步使用插件服务与扩展校验器；首次安装和停用插件不能依赖启动阶段已加载 Provider。
     * 此入口不注册插件路由、指令或协议，也不推进生命周期状态。
     *
     * @param array<string, mixed> $manifest 已验证的 manifest
     */
    public function prepareServices(string $directory, array $manifest): void
    {
        $this->autoloader->register($directory, $manifest);
        $this->registerProvider($directory, $manifest['code'], $manifest);
    }

    /** @param array<string,mixed> $manifest */
    private function registerFunctions(string $directory, array $manifest): void
    {
        $declared = $manifest['functions'] ?? null;
        if ($declared === null) {
            $default = $directory.'/functions.php';
            if (is_file($default)) {
                require_once $default;
            }
            return;
        }
        $paths = is_string($declared) ? [$declared] : (is_array($declared) ? $declared : []);
        foreach ($paths as $relative) {
            $relative = $this->safeRelative((string) $relative);
            if ($relative === '') {
                throw new AddonException('Addon functions declaration is invalid.', 'invalid_functions');
            }
            $path = $directory.'/'.$relative;
            if (!is_file($path)) {
                throw new AddonException('Addon functions file is unavailable.', 'functions_unavailable');
            }
            require_once $path;
        }
    }

    /** 映射先于所有插件入口，包含停用插件的身份占用但不加载其类。 */
    private function prepareAutoload(array $scan): array
    {
        $addons = [];
        foreach ($scan['addons'] as $addon) {
            $code = (string) $addon['code'];
            try {
                $this->autoloader->reserve($addon['manifest']);
                if (($addon['state'] ?? 'active') === 'active') {
                    $this->autoloader->register($this->addonsPath.'/'.$code, $addon['manifest']);
                }
                $addons[] = $addon;
            } catch (\Throwable $exception) {
                $errorCode = $exception instanceof AddonException ? $exception->errorCode : 'addon_autoload_failed';
                try {
                    $this->lifecycle->transition($code, 'inactive', [
                        'runtime_error_code' => $errorCode, 'runtime_error' => $exception->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // 诊断文件写入失败时仍返回最初的映射异常，不阻断其他插件启动。
                }
                $scan['errors'][] = ['code' => $code, 'error_code' => $errorCode, 'message' => $exception->getMessage()];
            }
        }
        $scan['addons'] = $addons;

        return $scan;
    }

    private function registerMigrationPath(string $directory): void
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
        $file = 'src/Bootstrap.php';
        if (!is_file($directory.'/'.$file)) {
            return;
        }
        $class = AddonNamespace::fromManifest($manifest).'\\Bootstrap';
        $path = $directory.'/'.$file;
        if (!is_file($path)) {
            throw new AddonException('Addon bootstrap file is unavailable.', 'bootstrap_unavailable');
        }
        if (!class_exists($class, false)) {
            require_once $path;
        }
        if (!class_exists($class, false)) {
            throw new AddonException('Addon bootstrap class is unavailable.', 'bootstrap_class_unavailable');
        }
        $instance = $this->app->make($class);
        if (!$instance instanceof BootstrapInterface) {
            throw new AddonException('Addon bootstrap class must implement BootstrapInterface.', 'invalid_bootstrap');
        }
        $instance->register($this->app, $code, $manifest);
    }

    /** @param array<string,mixed> $manifest */
    private function registerRoutes(string $directory, array $manifest): void
    {
        $routes = $manifest['routes'] ?? null;
        if ($routes === null) {
            $paths = glob($directory.'/routes/*.php') ?: [];
            sort($paths, SORT_STRING);
            $routes = array_map(static fn (string $path): string => 'routes/'.basename($path), $paths);
        }
        if (is_string($routes)) {
            $routes = [$routes];
        }
        if (!is_array($routes)) {
            throw new AddonException('Addon routes declaration is invalid.', 'invalid_routes');
        }
        foreach ($routes as $route) {
            if (!is_string($route) || ($relative = $this->safeRelative($route)) === '') {
                throw new AddonException('Addon route path is invalid.', 'invalid_routes');
            }
            $path = $directory.'/'.$relative;
            if (!is_file($path)) {
                throw new AddonException('Addon route file is unavailable.', 'routes_unavailable');
            }
            $registered = require $path;
            if (!$registered instanceof Closure) {
                continue;
            }

            // 插件 Routes 目录约定为后台 API。Core 统一提供会话认证，权限中间件仍由插件按接口声明。
            if ($this->app->bound('router')) {
                $this->app->make('router')->middleware('ptadmin.session')->group(
                    fn (): mixed => $registered($this->app)
                );
                continue;
            }

            // 纯单元测试或非 HTTP 启动场景没有 Router 时，仍允许验证插件其它运行时入口。
            $registered($this->app);
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return list<ServiceProvider>
     */
    private function registerProvider(string $directory, string $code, array $manifest): array
    {
        $providerValue = $manifest['provider'] ?? ($manifest['providers'] ?? null);
        if ($providerValue === null && !is_file($directory.'/src/Providers/ServiceProvider.php')) {
            return [];
        }
        if (is_array($providerValue) && array_is_list($providerValue)) {
            $providers = [];
            foreach ($providerValue as $entry) {
                $providers[] = $this->registerProviderDeclaration($directory, $code, $manifest, $entry);
            }
            return $providers;
        }
        $provider = is_string($providerValue) ? ['file' => $providerValue] : (is_array($providerValue) ? $providerValue : []);
        return [$this->registerProviderDeclaration($directory, $code, $manifest, $provider)];
    }

    /** @param array<string,mixed> $manifest */
    private function registerProviderDeclaration(string $directory, string $code, array $manifest, mixed $declaration): ServiceProvider
    {
        $provider = is_string($declaration) ? ['file' => $declaration] : (is_array($declaration) ? $declaration : []);
        $file = $this->safeRelative((string) ($provider['file'] ?? 'src/Providers/ServiceProvider.php'));
        $class = trim((string) ($provider['class'] ?? ''));
        if ($class === '') {
            $class = AddonNamespace::fromManifest($manifest).'\\Providers\\ServiceProvider';
        }
        if ($file === '') {
            throw new AddonException('Addon provider declaration is invalid.', 'invalid_provider');
        }
        $path = $directory.'/'.$file;
        if (!is_file($path)) {
            throw new AddonException('Addon provider file is unavailable.', 'provider_unavailable');
        }
        if (!class_exists($class, false)) {
            require_once $path;
        }
        if (!class_exists($class, false)) {
            throw new AddonException('Addon provider class is unavailable.', 'provider_class_unavailable');
        }
        if (!is_a($class, ProviderInterface::class, true) || !is_a($class, ServiceProvider::class, true)) {
            throw new AddonException('Addon provider must implement ProviderInterface and extend ServiceProvider.', 'invalid_provider');
        }
        return $this->app->register($class);
    }

    /** @param array<string,mixed> $manifest */
    private function registerConfig(string $directory, string $code, array $manifest): void
    {
        $declared = $manifest['config'] ?? 'config/config.php';
        $relative = is_string($declared) ? $this->safeRelative($declared) : '';
        if ($relative === '') {
            throw new AddonException('Addon config declaration is invalid.', 'invalid_config');
        }
        $path = $directory.'/'.$relative;
        if (!is_file($path)) {
            return;
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new AddonException('Addon config.php must return an array.', 'invalid_config');
        }
        config(['addon.'.strtolower($code) => $values]);
    }

    private function registerViewsAndTranslations(string $directory, string $code): void
    {
        $views = $directory.'/resources/views';
        if (is_dir($views)) {
            $callback = static function ($factory) use ($views, $code): void {
                $factory->addNamespace(strtolower($code), $views);
            };
            $this->app->resolved('view') ? $callback($this->app->make('view')) : $this->app->afterResolving('view', $callback);
        }
        $lang = $directory.'/resources/lang';
        if (is_dir($lang)) {
            $callback = static function ($translator) use ($lang, $code): void {
                $translator->addNamespace(strtolower($code), $lang);
            };
            $this->app->resolved('translator') ? $callback($this->app->make('translator')) : $this->app->afterResolving('translator', $callback);
        }
    }

    /** 允许直接构造 Registrar 的测试或精简宿主延迟创建隔离的指令 Loader。 */
    private function templateDirectiveLoader(): TemplateDirectiveLoader
    {
        return $this->templateDirectives ??= new TemplateDirectiveLoader($this->app, new DirectiveRegistry(), $this->autoloader);
    }

    /** @param array<string,mixed> $manifest */
    private function registerHooks(string $directory, string $code, array $manifest): void
    {
        $hooks = $manifest['hooks'] ?? [];
        if (!is_array($hooks)) {
            throw new AddonException('Addon hooks declaration is invalid.', 'invalid_hooks');
        }
        if ($hooks === []) {
            return;
        }
        $events = $this->app->make('events');
        if (!$events instanceof Dispatcher) {
            throw new AddonException('Addon event dispatcher is unavailable.', 'events_unavailable');
        }
        foreach ($hooks as $hook) {
            if (is_string($hook)) {
                $hook = ['file' => $hook];
            }
            if (!is_array($hook)) {
                throw new AddonException('Addon hook declaration is invalid.', 'invalid_hooks');
            }
            $file = $this->safeRelative((string) ($hook['file'] ?? ''));
            $class = trim((string) ($hook['class'] ?? ''));
            if ($file === '' || $class === '') {
                throw new AddonException('Addon hook declaration is invalid.', 'invalid_hooks');
            }
            $path = $directory.'/'.$file;
            if (!is_file($path)) {
                throw new AddonException('Addon hook file is unavailable.', 'hooks_unavailable');
            }
            require_once $path;
            if (!class_exists($class, false)) {
                throw new AddonException('Addon hook class is unavailable.', 'hook_class_unavailable');
            }
            $instance = $this->app->make($class);
            if (!$instance instanceof HookInterface) {
                throw new AddonException('Addon hook class must implement HookInterface.', 'invalid_hooks');
            }
            $instance->register($events, $code, $manifest);
        }
    }

    private function safeRelative(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $path);
        if ($path === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return '';
        }
        return $path;
    }

    /** @return array{file:string,class:string}|null */
    private function runtimeDeclaration(string $directory, string $code, array $manifest): ?array
    {
        $file = 'src/Runtime/Registrar.php';
        if (!is_file($directory.'/'.$file)) {
            return null;
        }

        return [
            'file' => $file,
            'class' => AddonNamespace::fromManifest($manifest).'\\Runtime\\Registrar',
        ];
    }

}
