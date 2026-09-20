<?php

declare(strict_types=1);

namespace PTAdmin\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Dashboard\DashboardService;
use PTAdmin\Admin\Schema\AdminRuntimeHook;
use PTAdmin\Admin\Console\Commands\AdminSyncCommand;
use PTAdmin\Admin\Console\Commands\MenuSyncCommand;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Auth\SchemaAuthorizationBridge;
use PTAdmin\Core\Auth\DataScopeRegistry;
use PTAdmin\Core\Auth\SchemaScopeResolver;
use PTAdmin\Admin\Http\Middleware\RequirePermissionMiddleware;
use PTAdmin\Admin\Http\Middleware\RecordAdminOperationMiddleware;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Http\Middleware\AuthenticateSessionMiddleware;
use PTAdmin\Core\Http\Middleware\RequestIdMiddleware;
use PTAdmin\Core\Http\Middleware\InstallStateMiddleware;
use PTAdmin\Core\Http\AdminFrontendRouteRegistrar;
use PTAdmin\Core\Install\InstallLock;
use PTAdmin\Core\Install\InstallStateMachine;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Install\WebPrefixGenerator;
use PTAdmin\Core\Install\EnvironmentFile;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Core\Install\InstallRequirementService;
use PTAdmin\Core\Install\InstallDatabaseConfigurator;
use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Core\Install\AdminFrontendPublisher;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Config\ConfigValueCodec;
use PTAdmin\Core\Dictionary\DictionaryResolver;
use PTAdmin\Core\Dictionary\DictionaryService;
use PTAdmin\Core\Dictionary\DictionarySynchronizer;
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;
use PTAdmin\Core\Tasks\TaskExecutorRegistry;
use PTAdmin\Core\Tasks\Executors\AdminFrontendUpdateExecutor;
use PTAdmin\Core\Tasks\Executors\AddonTaskExecutor;
use PTAdmin\Core\Console\Commands\TaskRunCommand;
use PTAdmin\Core\Console\Commands\TaskRecoverCommand;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use PTAdmin\Addon\Console\Commands\FrontendDevCommand;
use PTAdmin\Addon\Manifest\TrustPolicy;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Package\PackageDownloader;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Resource\ResourceSynchronizer;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Lifecycle\OperationLock;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Runtime\RuntimeRegistrar;
use PTAdmin\Addon\Runtime\RuntimeLifecycleValidator;
use PTAdmin\Addon\Runtime\TemplateDirectiveLoader;
use PTAdmin\Template\Builtin\BuiltinDirectiveProvider;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use PTAdmin\Template\Parsing\DirectiveArgumentParser;
use PTAdmin\Addon\Lifecycle\StateManager;
use PTAdmin\Schema\Providers\SchemaServiceProvider;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaScopeResolverInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Contracts\DictionaryResolverInterface;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Console\Commands\RuntimeCacheCommand;
use PTAdmin\Schema\Console\Commands\RuntimeValidateCommand;
use PTAdmin\Schema\SchemaFile\Loader as SchemaFileLoader;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use PTAdmin\Schema\Console\Commands\SchemaPublishCommand;
use PTAdmin\Schema\Console\Commands\SchemaSyncCommand;

final class PTAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/ptadmin.php', 'ptadmin');
        $this->app->register(SchemaServiceProvider::class);

        $this->app->singleton(ApplicationIdentity::class, static function (): ApplicationIdentity {
            return new ApplicationIdentity(storage_path('ptadmin.json'));
        });
        $this->app->singleton(InstallStateStore::class, static function (): InstallStateStore {
            return new InstallStateStore(storage_path('app/ptadmin/install'));
        });
        $this->app->singleton(InstallLock::class, static function ($app): InstallLock {
            return new InstallLock($app->make(InstallStateStore::class)->lockPath());
        });
        $this->app->singleton(WebPrefixGenerator::class);
        $this->app->singleton(EnvironmentFile::class, static function ($app): EnvironmentFile {
            return new EnvironmentFile($app->environmentFilePath());
        });
        $this->app->singleton(InstallService::class);
        $this->app->singleton(InstallDatabaseConfigurator::class);
        $this->app->singleton(AdminFrontendRouteRegistrar::class);
        $this->app->singleton(InstallRequirementService::class);
        $this->app->singleton(ConfigValueCodec::class);
        $this->app->singleton(ConfigService::class);
        $this->app->singleton(DictionaryService::class);
        $this->app->singleton(DictionarySynchronizer::class);
        $this->app->singleton(DictionaryResolver::class);
        $this->app->alias(DictionaryResolver::class, DictionaryResolverInterface::class);
        $this->app->singleton(TaskStore::class, static function (): TaskStore {
            return new TaskStore((string) config('ptadmin.tasks.path', storage_path('app/ptadmin/tasks')));
        });
        $this->app->singleton(TaskEventLog::class);
        $this->app->singleton(TaskManager::class);
        $this->app->singleton(TaskExecutorRegistry::class, static function ($app): TaskExecutorRegistry {
            $registry = new TaskExecutorRegistry();
            $registry->register('admin_frontend.update', $app->make(AdminFrontendUpdateExecutor::class));
            $addonExecutor = $app->make(AddonTaskExecutor::class);
            $registry->register('addon.download', $addonExecutor);
            $registry->register('addon.install', $addonExecutor);
            $registry->register('addon.update', $addonExecutor);
            $registry->register('addon.uninstall', $addonExecutor);
            $registry->register('addon.frontend_sync', $addonExecutor);
            $registry->register('addon.enable', $addonExecutor);
            $registry->register('addon.disable', $addonExecutor);
            return $registry;
        });
        $this->registerTemplateServices();
        $this->registerAddonManifestServices();
        $this->registerAddonFrontendServices();
        $this->registerAddonRuntimeServices();
        $this->registerAddonLifecycleServices();
        $this->app->singleton(SchemaFileLoader::class);
        $this->app->singleton(SchemaFileSynchronizer::class);
        $this->app->singleton(SchemaSourceDiscovery::class, static function ($app): SchemaSourceDiscovery {
            return new SchemaSourceDiscovery(
                $app->make(AddonCatalog::class),
                (string) $app['config']->get('ptadmin-schema.sources.application_path', base_path('database/schemas')),
                (string) $app['config']->get('ptadmin-schema.sources.core_path', __DIR__.'/../../../database/schemas'),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                (string) $app['config']->get('ptadmin-schema.sources.application_owner', 'host'),
            );
        });
        $this->app->singleton(AdminFrontendManifest::class, static function ($app): AdminFrontendManifest {
            return new AdminFrontendManifest(
                (string) $app['config']->get('ptadmin.backend_version', '0.1.0'),
                (int) $app['config']->get('ptadmin.api_protocol', 1),
                (int) $app['config']->get('ptadmin.schema_protocol', 1)
            );
        });
        $this->app->singleton(AdminFrontendPublisher::class, static function ($app): AdminFrontendPublisher {
            return new AdminFrontendPublisher(
                (string) $app['config']->get('ptadmin.frontend.bundled_path'),
                public_path(),
                $app->make(AdminFrontendManifest::class)
            );
        });
        $this->app->singleton(InstallStateMachine::class, static function ($app): InstallStateMachine {
            return new InstallStateMachine(
                $app->make(InstallStateStore::class),
                $app->make(InstallLock::class),
                $app->make(ApplicationIdentity::class),
                $app->make(WebPrefixGenerator::class),
                (string) $app['config']->get('ptadmin.backend_version', '0.1.0')
            );
        });
        $this->app->singleton(SessionManager::class, static function ($app): SessionManager {
            return new SessionManager(
                $app['db']->connection(),
                (array) $app['config']->get('ptadmin.session', [])
            );
        });
        $this->app->singleton(AdminAuthenticator::class);
        $this->app->singleton(AuthorizationService::class);
        $this->app->singleton(DataScopeRegistry::class);
        $this->app->singleton(DashboardService::class);
        $this->app->singleton(MenuSynchronizer::class, static function ($app): MenuSynchronizer {
            return new MenuSynchronizer(
                $app['db'],
                $app->make(AddonCatalog::class),
                __DIR__.'/../../../resources/menu.json',
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
            );
        });
        $this->app->singleton(AdminRuntimeHook::class);
        $this->app->singleton(SchemaAuthorizationInterface::class, SchemaAuthorizationBridge::class);
        $this->app->singleton(SchemaScopeResolverInterface::class, SchemaScopeResolver::class);
        $this->app->alias(SchemaSubjectContextInterface::class, 'ptadmin.schema.subject');
    }

    private function registerTemplateServices(): void
    {
        $this->app->singleton(DirectiveRegistry::class, static function (): DirectiveRegistry {
            $registry = new DirectiveRegistry();
            $registration = $registry->forNamespace('core');
            (new BuiltinDirectiveProvider())->register($registration);
            $registration->commit();

            return $registry;
        });
        $this->app->singleton(DirectiveArgumentParser::class);
        $this->app->singleton(TemplateRenderer::class);
    }

    private function registerAddonManifestServices(): void
    {
        $this->app->singleton(TrustPolicy::class, static function ($app): TrustPolicy {
            return new TrustPolicy(
                (array) $app['config']->get('ptadmin.addon.trust.keys', []),
                (array) $app['config']->get('ptadmin.addon.trust.publishers', []),
                (bool) $app['config']->get('ptadmin.addon.trust.allow_local', false),
                (string) $app->environment()
            );
        });
        $this->app->singleton(ManifestVerifier::class, static function ($app): ManifestVerifier {
            return new ManifestVerifier(
                (string) $app['config']->get('ptadmin.backend_version', '0.1.0'),
                (int) $app['config']->get('ptadmin.schema_protocol', 1),
                $app->make(TrustPolicy::class)
            );
        });
        $this->app->singleton(PackageExtractor::class);
        $this->app->singleton(PackageDownloader::class);
    }

    private function registerAddonFrontendServices(): void
    {
        $this->app->singleton(FrontendDevelopmentStore::class, static function ($app): FrontendDevelopmentStore {
            return new FrontendDevelopmentStore(
                (string) $app['config']->get('ptadmin.frontend.development.path', storage_path('app/ptadmin/dev/frontend')),
                (string) $app->environment(),
                (array) $app['config']->get('ptadmin.frontend.development.allowed_origins', []),
                (int) $app['config']->get('ptadmin.frontend.development.lease_seconds', 120),
            );
        });
        $this->app->singleton(FrontendPublisher::class, static function (): FrontendPublisher {
            return new FrontendPublisher(public_path());
        });
        $this->app->singleton(FrontendRegistry::class, static function ($app): FrontendRegistry {
            return new FrontendRegistry(
                $app->make(AddonCatalog::class),
                $app->make(ManifestVerifier::class),
                $app->make(TrustPolicy::class),
                $app->make(LifecycleStore::class),
                $app->make(FrontendDevelopmentStore::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                public_path(),
                (string) $app['config']->get('ptadmin.web_prefix', 'admin'),
                (string) $app['config']->get('ptadmin.api_prefix', 'ptadmin'),
                (array) $app['config']->get('ptadmin.frontend.host_apis', ['v1']),
                (array) $app['config']->get('ptadmin.frontend.runtime_profiles', ['admin-v1']),
                (array) $app['config']->get('ptadmin.frontend.capabilities', []),
            );
        });
    }

    private function registerAddonRuntimeServices(): void
    {
        $this->app->singleton(ResourceSynchronizer::class);
        $this->app->alias(ResourceSynchronizer::class, ResourceSynchronizerInterface::class);
        $this->app->singleton(AddonCatalog::class, static function ($app): AddonCatalog {
            return new AddonCatalog(
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(ManifestVerifier::class),
                $app->make(LifecycleStore::class),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true)
            );
        });
        $this->app->singleton(TemplateDirectiveLoader::class);
        $this->app->singleton(RuntimeRegistrar::class, static function ($app): RuntimeRegistrar {
            return new RuntimeRegistrar(
                $app,
                $app->make(AddonCatalog::class),
                $app->make(LifecycleStore::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(TemplateDirectiveLoader::class),
                $app->make(RuntimeRegistry::class),
            );
        });
        $this->app->singleton(RuntimeLifecycleValidator::class);
    }

    private function registerAddonLifecycleServices(): void
    {
        $this->app->singleton(LifecycleStore::class, static function (): LifecycleStore {
            return new LifecycleStore(storage_path('app/ptadmin/addons'));
        });
        $this->app->singleton(OperationLock::class, static function (): OperationLock {
            return new OperationLock(storage_path('app/ptadmin/addons/locks'));
        });
        $this->app->singleton(StateManager::class, static function ($app): StateManager {
            return new StateManager(
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(ManifestVerifier::class),
                $app->make(LifecycleStore::class),
                $app->make(OperationLock::class),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true),
                $app->make(ResourceSynchronizerInterface::class),
                $app->make(RuntimeCacheStore::class),
                $app->make(RuntimeLifecycleValidator::class),
            );
        });
        $this->app->singleton(Installer::class, static function ($app): Installer {
            return new Installer(
                $app->make(ManifestVerifier::class),
                $app->make(PackageExtractor::class),
                $app->make(LifecycleStore::class),
                $app->make(FrontendPublisher::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                (string) $app['config']->get('ptadmin.addon.download_path', storage_path('app/ptadmin/addons/downloads')),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true),
                $app->make(ResourceSynchronizer::class),
                $app['db']->connection(),
                $app->make(OperationLock::class),
                $app->make(RuntimeCacheStore::class),
                $app->make(RuntimeLifecycleValidator::class),
            );
        });
    }

    public function boot(): void
    {
        $installState = $this->app->make(InstallStateStore::class);
        $isInstalled = $installState->isInstalled();
        if ($isInstalled) {
            $installedMarker = $installState->readInstalled();
            if (is_array($installedMarker)) {
                config(['ptadmin.web_prefix' => (string) $installedMarker['web_prefix']]);
            }
        }

        $this->app['router']->aliasMiddleware('ptadmin.request-id', RequestIdMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.install', InstallStateMiddleware::class);

        $this->publishes([
            __DIR__.'/../../../config/ptadmin.php' => config_path('ptadmin.php'),
        ], 'ptadmin-config');

        $this->loadTranslationsFrom(__DIR__.'/../../../lang', 'ptadmin');
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        // 安装时也要同步内置 Schema；这里只注册描述符，不解析数据库连接。
        $this->registerAdminRuntimeBindings();

        if (!$isInstalled) {
            $this->app->make(HttpKernel::class)->prependMiddlewareToGroup('web', InstallStateMiddleware::class);
            $this->loadViewsFrom(__DIR__.'/../../../resources/views/install', 'ptadmin-install');
            $this->loadRoutesFrom(__DIR__.'/../../../routes/install.php');

            return;
        }

        $this->app['router']->aliasMiddleware('ptadmin.session', AuthenticateSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.permission', RequirePermissionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.audit', RecordAdminOperationMiddleware::class);
        $this->loadRoutesFrom(__DIR__.'/../../../routes/ptadmin.php');
        if ((bool) $this->app['config']->get('ptadmin.addon.runtime.enabled', true)) {
            $this->app->make(RuntimeRegistrar::class)->register();
        }
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            TaskRunCommand::class,
            TaskRecoverCommand::class,
            SchemaSyncCommand::class,
            SchemaPublishCommand::class,
            RuntimeCacheCommand::class,
            RuntimeValidateCommand::class,
            AdminSyncCommand::class,
            MenuSyncCommand::class,
            FrontendDevCommand::class,
        ]);
    }

    private function registerAdminRuntimeBindings(): void
    {
        $owner = $this->app->make(RuntimeRegistry::class)->forOwner('core', 'ptadmin');
        $loader = $this->app->make(SchemaFileLoader::class);
        foreach ($this->app->make(SchemaSourceDiscovery::class)->discoverCore() as $file) {
            if (!str_starts_with($file['relative_path'], 'admin/')) {
                continue;
            }

            $definition = $loader->resourceFromFile($file['path']);
            $resourceKey = $definition['resource'];
            $schema = $definition['schema']->toArray();
            $actions = array_values(array_filter(
                array_keys((array) ($schema['actions'] ?? [])),
                static fn (string $action): bool => !in_array($action, ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge'], true),
            ));
            $resource = $owner->resource($resourceKey);
            $resource
                ->hook('create.prepare', AdminRuntimeHook::class, 'prepareCreate')
                ->hook('create.after', AdminRuntimeHook::class, 'created')
                ->hook('update.prepare', AdminRuntimeHook::class, 'prepareUpdate')
                ->hook('update.after', AdminRuntimeHook::class, 'updated')
                ->hook('delete.before', AdminRuntimeHook::class, 'deleting')
                ->hook('delete.after', AdminRuntimeHook::class, 'deleted')
                ->hook('output.transform', AdminRuntimeHook::class, 'output');
            foreach ($actions as $actionKey) {
                $resource->action($actionKey, AdminRuntimeHook::class, 'executeAction');
            }
        }
    }
}
