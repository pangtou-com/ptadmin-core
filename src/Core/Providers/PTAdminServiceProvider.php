<?php

declare(strict_types=1);

namespace PTAdmin\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Dashboard\DashboardService;
use PTAdmin\Admin\Schema\AdminRuntimeHook;
use PTAdmin\Admin\Schema\AdminSchemaDefinitions;
use PTAdmin\Admin\Schema\AdminSchemaSynchronizer;
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
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;
use PTAdmin\Core\Tasks\TaskExecutorRegistry;
use PTAdmin\Core\Tasks\Executors\AdminFrontendUpdateExecutor;
use PTAdmin\Core\Tasks\Executors\AddonTaskExecutor;
use PTAdmin\Core\Console\Commands\TaskRunCommand;
use PTAdmin\Core\Console\Commands\TaskRecoverCommand;
use PTAdmin\Addon\AddonManifestVerifier;
use PTAdmin\Addon\AddonLifecycleStore;
use PTAdmin\Addon\AddonFrontendPublisher;
use PTAdmin\Addon\AddonFrontendRegistry;
use PTAdmin\Addon\AddonTrustPolicy;
use PTAdmin\Addon\AddonPackageExtractor;
use PTAdmin\Addon\AddonPackageDownloader;
use PTAdmin\Addon\AddonInstaller;
use PTAdmin\Addon\AddonResourceSynchronizer;
use PTAdmin\Addon\AddonResourceSynchronizerInterface;
use PTAdmin\Addon\AddonLock;
use PTAdmin\Addon\AddonDiscovery;
use PTAdmin\Addon\AddonRuntimeRegistrar;
use PTAdmin\Addon\AddonRuntimeLifecycleValidator;
use PTAdmin\Addon\AddonTemplateDirectiveRegistry;
use PTAdmin\Addon\AddonTemplateRuntime;
use PTAdmin\Addon\TemplateDirectiveArgumentParser;
use PTAdmin\Addon\AddonStateManager;
use PTAdmin\Schema\Providers\SchemaServiceProvider;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaScopeResolverInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
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
        $this->app->singleton(AddonManifestVerifier::class, static function ($app): AddonManifestVerifier {
            return new AddonManifestVerifier(
                (string) $app['config']->get('ptadmin.backend_version', '0.1.0'),
                (int) $app['config']->get('ptadmin.schema_protocol', 1),
                $app->make(AddonTrustPolicy::class)
            );
        });
        $this->app->singleton(AddonTrustPolicy::class, static function ($app): AddonTrustPolicy {
            return new AddonTrustPolicy(
                (array) $app['config']->get('ptadmin.addon.trust.keys', []),
                (array) $app['config']->get('ptadmin.addon.trust.publishers', []),
                (bool) $app['config']->get('ptadmin.addon.trust.allow_local', false),
                (string) $app->environment()
            );
        });
        $this->app->singleton(AddonLifecycleStore::class, static function (): AddonLifecycleStore {
            return new AddonLifecycleStore(storage_path('app/ptadmin/addons'));
        });
        $this->app->singleton(AddonFrontendPublisher::class, static function (): AddonFrontendPublisher {
            return new AddonFrontendPublisher(public_path());
        });
        $this->app->singleton(AddonFrontendRegistry::class, static function ($app): AddonFrontendRegistry {
            return new AddonFrontendRegistry(
                $app->make(AddonDiscovery::class),
                $app->make(AddonManifestVerifier::class),
                $app->make(AddonTrustPolicy::class),
                $app->make(AddonLifecycleStore::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                public_path(),
                (string) $app['config']->get('ptadmin.web_prefix', 'admin'),
                (array) $app['config']->get('ptadmin.frontend.host_apis', ['admin-v1']),
                (array) $app['config']->get('ptadmin.frontend.runtime_profiles', ['admin-v1']),
                (array) $app['config']->get('ptadmin.frontend.capabilities', []),
            );
        });
        $this->app->singleton(AddonPackageExtractor::class);
        $this->app->singleton(AddonPackageDownloader::class);
        $this->app->singleton(AddonResourceSynchronizer::class);
        $this->app->alias(AddonResourceSynchronizer::class, AddonResourceSynchronizerInterface::class);
        $this->app->singleton(AddonLock::class, static function (): AddonLock {
            return new AddonLock(storage_path('app/ptadmin/addons/locks'));
        });
        $this->app->singleton(AddonDiscovery::class, static function ($app): AddonDiscovery {
            return new AddonDiscovery(
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(AddonManifestVerifier::class),
                $app->make(AddonLifecycleStore::class),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true)
            );
        });
        $this->app->singleton(AddonRuntimeRegistrar::class, static function ($app): AddonRuntimeRegistrar {
            return new AddonRuntimeRegistrar(
                $app,
                $app->make(AddonDiscovery::class),
                $app->make(AddonLifecycleStore::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(AddonTemplateDirectiveRegistry::class),
                $app->make(RuntimeRegistry::class),
            );
        });
        $this->app->singleton(AddonRuntimeLifecycleValidator::class);
        $this->app->singleton(AddonTemplateDirectiveRegistry::class);
        $this->app->singleton(TemplateDirectiveArgumentParser::class);
        $this->app->singleton(AddonTemplateRuntime::class);
        $this->app->singleton(AddonStateManager::class, static function ($app): AddonStateManager {
            return new AddonStateManager(
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(AddonManifestVerifier::class),
                $app->make(AddonLifecycleStore::class),
                $app->make(AddonLock::class),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true),
                $app->make(AddonResourceSynchronizerInterface::class),
                $app->make(RuntimeCacheStore::class),
                $app->make(AddonRuntimeLifecycleValidator::class),
            );
        });
        $this->app->singleton(SchemaFileLoader::class);
        $this->app->singleton(SchemaFileSynchronizer::class);
        $this->app->singleton(SchemaSourceDiscovery::class, static function ($app): SchemaSourceDiscovery {
            return new SchemaSourceDiscovery(
                $app->make(AddonDiscovery::class),
                (string) $app['config']->get('ptadmin-schema.sources.application_path', base_path('database/schemas')),
                (string) $app['config']->get('ptadmin-schema.sources.core_path', __DIR__.'/../../../database/schemas'),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                (string) $app['config']->get('ptadmin-schema.sources.application_owner', 'host'),
            );
        });
        $this->app->singleton(AddonInstaller::class, static function ($app): AddonInstaller {
            return new AddonInstaller(
                $app->make(AddonManifestVerifier::class),
                $app->make(AddonPackageExtractor::class),
                $app->make(AddonLifecycleStore::class),
                $app->make(AddonFrontendPublisher::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                (string) $app['config']->get('ptadmin.addon.download_path', storage_path('app/ptadmin/addons/downloads')),
                (bool) $app['config']->get('ptadmin.addon.require_signature', true),
                $app->make(AddonResourceSynchronizer::class),
                $app['db']->connection(),
                $app->make(AddonLock::class),
                $app->make(RuntimeCacheStore::class),
                $app->make(AddonRuntimeLifecycleValidator::class),
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
        $this->app->singleton(AdminSchemaDefinitions::class);
        $this->app->singleton(AdminSchemaSynchronizer::class);
        $this->app->singleton(MenuSynchronizer::class, static function ($app): MenuSynchronizer {
            return new MenuSynchronizer(
                $app['db'],
                $app->make(AddonDiscovery::class),
                __DIR__.'/../../../resources/menu.json',
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
            );
        });
        $this->app->singleton(AdminRuntimeHook::class);
        $this->app->singleton(SchemaAuthorizationInterface::class, SchemaAuthorizationBridge::class);
        $this->app->singleton(SchemaScopeResolverInterface::class, SchemaScopeResolver::class);
        $this->app->alias(SchemaSubjectContextInterface::class, 'ptadmin.schema.subject');
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
        $this->app['router']->aliasMiddleware('ptadmin.session', AuthenticateSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.permission', RequirePermissionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.audit', RecordAdminOperationMiddleware::class);

        $this->publishes([
            __DIR__.'/../../../config/ptadmin.php' => config_path('ptadmin.php'),
        ], 'ptadmin-config');

        $this->loadTranslationsFrom(__DIR__.'/../../../lang', 'ptadmin');
        if (!$isInstalled) {
            $this->app->make(HttpKernel::class)->prependMiddlewareToGroup('web', InstallStateMiddleware::class);
        }
        $this->loadRoutesFrom(__DIR__.'/../../../routes/public.php');
        // Installation is a bootstrap mode. Its routes and views are selected once
        // when the application boots, rather than guarding every business request.
        if (!$isInstalled) {
            $this->loadViewsFrom(__DIR__.'/../../../resources/views/install', 'ptadmin-install');
            $this->loadRoutesFrom(__DIR__.'/../../../routes/install.php');
        } else {
            $this->loadRoutesFrom(__DIR__.'/../../../routes/web.php');
            $this->loadRoutesFrom(__DIR__.'/../../../routes/admin.php');
        }
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->registerAdminRuntimeBindings();
        if ((bool) $this->app['config']->get('ptadmin.addon.runtime.enabled', true)) {
            $this->app->make(AddonRuntimeRegistrar::class)->register();
        }
        if ($this->app->runningInConsole()) {
            $this->commands([
                TaskRunCommand::class,
                TaskRecoverCommand::class,
                SchemaSyncCommand::class,
                SchemaPublishCommand::class,
                RuntimeCacheCommand::class,
                RuntimeValidateCommand::class,
                AdminSyncCommand::class,
                MenuSyncCommand::class,
            ]);
        }
    }

    private function registerAdminRuntimeBindings(): void
    {
        $hook = $this->app->make(AdminRuntimeHook::class);
        $actions = [];
        foreach ($this->app->make(AdminSchemaDefinitions::class)->all() as $resourceKey => $definition) {
            $declared = (array) ($definition['schema']['actions'] ?? []);
            $actions[$resourceKey] = array_values(array_filter(
                array_keys($declared),
                static fn (string $action): bool => !in_array($action, ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge'], true),
            ));
        }
        $owner = $this->app->make(RuntimeRegistry::class)->forOwner('core', 'ptadmin');
        foreach (array_keys($this->app->make(AdminSchemaDefinitions::class)->all()) as $resourceKey) {
            $resource = $owner->resource($resourceKey);
            $resource
                ->hook('create.prepare', AdminRuntimeHook::class, 'prepareCreate')
                ->hook('create.after', AdminRuntimeHook::class, 'created')
                ->hook('update.prepare', AdminRuntimeHook::class, 'prepareUpdate')
                ->hook('update.after', AdminRuntimeHook::class, 'updated')
                ->hook('delete.before', AdminRuntimeHook::class, 'deleting')
                ->hook('delete.after', AdminRuntimeHook::class, 'deleted')
                ->hook('output.transform', AdminRuntimeHook::class, 'output');
            foreach ($actions[$resourceKey] ?? [] as $actionKey) {
                $resource->action($actionKey, AdminRuntimeHook::class, 'executeAction');
            }
        }
    }
}
