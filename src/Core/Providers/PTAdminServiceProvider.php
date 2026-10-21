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
use PTAdmin\Core\Config\ApplicationConfigSynchronizer;
use PTAdmin\Core\Config\ConfigValueCodec;
use PTAdmin\Core\Dictionary\DictionaryResolver;
use PTAdmin\Core\Dictionary\DictionaryService;
use PTAdmin\Core\Dictionary\DictionarySynchronizer;
use PTAdmin\Notice\NoticeRegistry;
use PTAdmin\Notice\NoticeSynchronizer;
use PTAdmin\Notice\NoticeChannel;
use PTAdmin\Notice\NoticeChannelAdapterRegistry;
use PTAdmin\Notice\NoticeChannelRouter;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeManager;
use PTAdmin\Notice\NoticeTemplateResolver;
use PTAdmin\Notice\NoticeTemplateValidator;
use PTAdmin\Notice\Adapters\LaravelMailNoticeChannelAdapter;
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;
use PTAdmin\Core\Tasks\TaskExecutorRegistry;
use PTAdmin\Core\Tasks\Executors\AdminFrontendUpdateExecutor;
use PTAdmin\Core\Tasks\Executors\AddonTaskExecutor;
use PTAdmin\Core\Tasks\Executors\ThemeTaskExecutor;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\CloudSessionStoreInterface;
use PTAdmin\Cloud\EncryptedCloudSessionStore;
use PTAdmin\Cloud\FounderAccess;
use PTAdmin\Cloud\HttpCloudClient;
use PTAdmin\Core\Theme\ActiveThemeResolver;
use PTAdmin\Core\Theme\ThemeCatalog;
use PTAdmin\Core\Theme\ThemeManifestValidator;
use PTAdmin\Core\Theme\ThemeAssetResolver;
use PTAdmin\Core\Http\Controllers\ThemeDevelopmentAssetController;
use PTAdmin\Core\Theme\ThemeDirectiveProvider;
use PTAdmin\Core\Theme\ThemeStateStore;
use PTAdmin\Core\Theme\ThemePackageExtractor;
use PTAdmin\Core\Theme\ThemePublisher;
use PTAdmin\Core\Theme\ThemeInstaller;
use PTAdmin\Core\Theme\ThemeLifecycle;
use PTAdmin\Core\Theme\ThemeDataPackRegistry;
use PTAdmin\Core\Theme\ThemeDataPackImporter;
use PTAdmin\Core\Theme\ThemeViewLoader;
use PTAdmin\Core\Console\Commands\TaskRunCommand;
use PTAdmin\Core\Console\Commands\TaskRecoverCommand;
use PTAdmin\Core\Console\Commands\UnifiedSyncCommand;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Admin\Frontend\ApplicationFrontendRegistry as AdminApplicationFrontendRegistry;
use PTAdmin\Admin\Frontend\ApplicationFrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use PTAdmin\Addon\Console\Commands\AddonSetupCommand;
use PTAdmin\Addon\Manifest\TrustPolicy;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Package\PackageDownloader;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleRegistrar;
use PTAdmin\Addon\Resource\ResourceSynchronizer;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Lifecycle\OperationLock;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Runtime\RuntimeRegistrar;
use PTAdmin\Addon\Runtime\AddonAutoloader;
use PTAdmin\Addon\Runtime\RuntimeLifecycleValidator;
use PTAdmin\Addon\Runtime\TemplateDirectiveLoader;
use PTAdmin\Template\Builtin\BuiltinDirectiveProvider;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use PTAdmin\Template\Builtin\PublicConfigDirectiveProvider;
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
    private bool $adminRuntimeBindingsRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/ptadmin.php', 'ptadmin');
        $this->app->register(SchemaServiceProvider::class);
        $this->app->register(ServiceProtocolServiceProvider::class);

        $this->app->singleton(ApplicationIdentity::class, static function (): ApplicationIdentity {
            return new ApplicationIdentity(storage_path('ptadmin.json'));
        });
        $this->app->singleton(InstallStateStore::class, static function (): InstallStateStore {
            return new InstallStateStore(storage_path('app/ptadmin/install'));
        });
        $this->app->booting(function (): void {
            if ($this->app->make(InstallStateStore::class)->isInstalled()) {
                return;
            }

            // 宿主注册限流器时就会解析缓存，必须在所有 Provider boot 前隔离数据库缓存。
            $config = $this->app['config'];
            foreach (['cache.default', 'cache.limiter'] as $key) {
                $store = $config->get($key);
                if (is_string($store) && $config->get('cache.stores.'.$store.'.driver') === 'database') {
                    $config->set('cache.stores.ptadmin-install', [
                        'driver' => 'file',
                        'path' => storage_path('app/ptadmin/install/cache'),
                    ]);
                    $config->set($key, 'ptadmin-install');
                }
            }
            // Laravel 的跳转响应也会解析 Session，即使安装路由没有使用 web 中间件。
            if ($config->get('session.driver') === 'database') {
                $config->set('session.driver', 'array');
            }
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
        $this->app->singleton(ApplicationConfigSynchronizer::class, static function ($app): ApplicationConfigSynchronizer {
            return new ApplicationConfigSynchronizer(
                $app->make(ConfigService::class),
                $app['db'],
                base_path(),
            );
        });
        $this->app->singleton(\PTAdmin\Core\Config\SystemMailTransport::class, static function ($app): \PTAdmin\Core\Config\SystemMailTransport {
            return new \PTAdmin\Core\Config\SystemMailTransport($app->make(ConfigService::class), $app->make('mail.manager'));
        });
        $this->app->singleton(DictionaryService::class);
        $this->app->singleton(DictionarySynchronizer::class);
        $this->app->singleton(NoticeRegistry::class);
        $this->app->singleton(NoticeSynchronizer::class);
        $this->app->singleton(LaravelMailNoticeChannelAdapter::class);
        $this->app->singleton(NoticeChannelAdapterRegistry::class, static function ($app): NoticeChannelAdapterRegistry {
            $registry = new NoticeChannelAdapterRegistry($app);
            $registry->register(NoticeChannel::EMAIL_CODE, LaravelMailNoticeChannelAdapter::class);

            return $registry;
        });
        $this->app->singleton(NoticeChannelRouter::class);
        $this->app->singleton(NoticeInboxService::class);
        $this->app->singleton(NoticeManager::class);
        $this->app->singleton(NoticeTemplateResolver::class);
        $this->app->singleton(NoticeTemplateValidator::class);
        $this->app->singleton(DictionaryResolver::class);
        $this->app->alias(DictionaryResolver::class, DictionaryResolverInterface::class);
        $this->app->singleton(TaskStore::class, static function (): TaskStore {
            return new TaskStore((string) config('ptadmin.tasks.path', storage_path('app/ptadmin/tasks')));
        });
        $this->app->singleton(TaskEventLog::class);
        $this->app->singleton(TaskManager::class);
        $this->app->singleton(\PTAdmin\Cloud\Contracts\CloudTransportInterface::class, \PTAdmin\Cloud\HttpCloudTransport::class);
        $this->app->singleton(CloudClientInterface::class, HttpCloudClient::class);
        $this->app->singleton(\PTAdmin\Cloud\Contracts\CloudCatalogInterface::class, static fn ($app) => $app->make(CloudClientInterface::class));
        $this->app->singleton(\PTAdmin\Cloud\Contracts\ProductDeliveryClientInterface::class, HttpCloudClient::class);
        $this->app->singleton(CloudSessionStoreInterface::class, static function ($app): CloudSessionStoreInterface {
            return new EncryptedCloudSessionStore(
                (string) $app['config']->get('ptadmin.cloud.session_path', storage_path('app/ptadmin/cloud/session.json')),
                $app->make('encrypter'),
            );
        });
        $this->app->singleton(FounderAccess::class);
        $this->app->singleton(TaskExecutorRegistry::class, static function ($app): TaskExecutorRegistry {
            $registry = new TaskExecutorRegistry();
            $registry->register(\PTAdmin\Cloud\CloudPackageExecutor::TYPE, $app->make(\PTAdmin\Cloud\CloudPackageExecutor::class));
            $registry->register('admin_frontend.update', $app->make(AdminFrontendUpdateExecutor::class));
            $addonExecutor = $app->make(AddonTaskExecutor::class);
            $registry->register('addon.download', $addonExecutor);
            $registry->register('addon.install', $addonExecutor);
            $registry->register('addon.update', $addonExecutor);
            $registry->register('addon.uninstall', $addonExecutor);
            $registry->register('addon.frontend_sync', $addonExecutor);
            $registry->register('addon.enable', $addonExecutor);
            $registry->register('addon.disable', $addonExecutor);
            $registry->register(TaskManager::SITE_THEME_INSTALL, $app->make(ThemeTaskExecutor::class));
            $registry->register(TaskManager::SITE_THEME_ACTIVATE, $app->make(ThemeTaskExecutor::class));
            $registry->register(TaskManager::SITE_THEME_REMOVE, $app->make(ThemeTaskExecutor::class));
            return $registry;
        });
        $this->registerTemplateServices();
        $this->registerThemeServices();
        $this->app->singleton(ThemeDataPackRegistry::class);
        $this->app->singleton(ThemeDataPackImporter::class);
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
        $this->app->bind(\PTAdmin\Admin\Auth\AuthorizationIdentityResolverInterface::class, \PTAdmin\Admin\Auth\AccountAuthorizationIdentityResolver::class);
        $this->app->singleton(DataScopeRegistry::class);
        $this->app->singleton(DashboardService::class);
        $this->app->singleton(MenuSynchronizer::class, static function ($app): MenuSynchronizer {
            return new MenuSynchronizer(
                $app['db'],
                $app->make(AddonCatalog::class),
                __DIR__.'/../../../resources/menu.json',
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                base_path(),
            );
        });
        $this->app->singleton(AdminRuntimeHook::class);
        $this->app->singleton(SchemaAuthorizationInterface::class, SchemaAuthorizationBridge::class);
        $this->app->singleton(SchemaScopeResolverInterface::class, SchemaScopeResolver::class);
        $this->app->alias(SchemaSubjectContextInterface::class, 'ptadmin.schema.subject');
    }

    private function registerTemplateServices(): void
    {
        $this->app->singleton(DirectiveRegistry::class, function (): DirectiveRegistry {
            $registry = new DirectiveRegistry();
            $registration = $registry->forNamespace('core');
            (new BuiltinDirectiveProvider())->register($registration);
            (new PublicConfigDirectiveProvider($this->app->make(ConfigService::class)))->register($registration);
            (new ThemeDirectiveProvider($this->app->make(ThemeAssetResolver::class)))->register($registration);
            $registration->commit();

            return $registry;
        });
        $this->app->singleton(DirectiveArgumentParser::class);
        $this->app->singleton(TemplateRenderer::class);
    }

    private function registerThemeServices(): void
    {
        $this->app->singleton(ThemeManifestValidator::class, static function ($app): ThemeManifestValidator {
            return new ThemeManifestValidator((string) $app['config']->get('ptadmin.backend_version', '0.1.0'));
        });
        $this->app->singleton(ThemeCatalog::class, static function ($app): ThemeCatalog {
            return new ThemeCatalog(
                (string) $app['config']->get('ptadmin.site_theme.directory', base_path('templates')),
                $app->make(ThemeManifestValidator::class),
            );
        });
        $this->app->singleton(ActiveThemeResolver::class, static function ($app): ActiveThemeResolver {
            $default = (array) $app['config']->get('ptadmin.site_theme.default', []);

            return new ActiveThemeResolver(
                $app->make(ThemeCatalog::class),
                (string) $app['config']->get('ptadmin.site_theme.state_file', storage_path('theme.json')),
                (string) ($default['code'] ?? 'default'),
                (string) ($default['version'] ?? '1.0.0'),
            );
        });
        $this->app->singleton(ThemeAssetResolver::class, static function ($app): ThemeAssetResolver {
            return new ThemeAssetResolver(
                $app->make(ActiveThemeResolver::class),
                (array) $app['config']->get('ptadmin.site_theme.development_assets', []),
            );
        });
        $this->app->singleton(ThemeViewLoader::class, static function ($app): ThemeViewLoader {
            return new ThemeViewLoader($app['view']->getFinder());
        });
        $this->app->singleton(ThemeStateStore::class, static function ($app): ThemeStateStore {
            return new ThemeStateStore((string) $app['config']->get('ptadmin.site_theme.state_file', storage_path('theme.json')));
        });
        $this->app->singleton(ThemePackageExtractor::class);
        $this->app->singleton(ThemePublisher::class, static function ($app): ThemePublisher {
            return new ThemePublisher((string) $app['config']->get('ptadmin.site_theme.public_directory', public_path('themes')));
        });
        $this->app->singleton(ThemeInstaller::class, static function ($app): ThemeInstaller {
            return new ThemeInstaller(
                $app->make(ThemePackageExtractor::class),
                $app->make(ThemeManifestValidator::class),
                (string) $app['config']->get('ptadmin.site_theme.directory', base_path('templates')),
                (string) $app['config']->get('ptadmin.site_theme.staging_directory', storage_path('app/ptadmin/themes/staging')),
            );
        });
        $this->app->singleton(ThemeTaskExecutor::class);
        $this->app->singleton(ThemeLifecycle::class, static function ($app): ThemeLifecycle {
            $default = (array) $app['config']->get('ptadmin.site_theme.default', []);

            return new ThemeLifecycle(
                $app->make(ThemeCatalog::class),
                $app->make(ActiveThemeResolver::class),
                $app->make(ThemeStateStore::class),
                $app->make(ThemePublisher::class),
                (string) $app['config']->get('ptadmin.site_theme.directory', base_path('templates')),
                (string) ($default['code'] ?? 'default'),
                (string) ($default['version'] ?? '1.0.0'),
                $app->make(ThemeViewLoader::class),
            );
        });
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
                (string) $app->environment(),
                (array) $app['config']->get('ptadmin.frontend.development.allowed_origins', []),
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
        $this->app->singleton(AdminApplicationFrontendRegistry::class, static function ($app): AdminApplicationFrontendRegistry {
            return new AdminApplicationFrontendRegistry(
                base_path(),
                public_path(),
                (string) $app['config']->get('ptadmin.web_prefix', 'admin'),
                (string) $app['config']->get('ptadmin.api_prefix', 'ptadmin'),
                (array) $app['config']->get('ptadmin.frontend.host_apis', ['v1']),
                (array) $app['config']->get('ptadmin.frontend.runtime_profiles', ['admin-v1']),
                (array) $app['config']->get('ptadmin.frontend.capabilities', []),
                $app->make(FrontendDevelopmentStore::class),
            );
        });
        $this->app->singleton(ApplicationFrontendPublisher::class);
    }

    private function registerAddonRuntimeServices(): void
    {
        $this->app->singleton(\PTAdmin\Addon\Runtime\AddonRegistration::class);
        $this->app->singleton(AddonAutoloader::class);
        $this->app->singleton(ResourceSynchronizer::class);
        $this->app->alias(ResourceSynchronizer::class, ResourceSynchronizerInterface::class);
        $this->app->singleton(AddonCatalog::class, static function ($app): AddonCatalog {
            return new AddonCatalog(
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(ManifestVerifier::class),
                $app->make(LifecycleStore::class),
            );
        });
        $this->app->singleton(TemplateDirectiveLoader::class);
        $this->app->singleton(RuntimeRegistrar::class, static function ($app): RuntimeRegistrar {
            return new RuntimeRegistrar(
                $app,
                $app->make(AddonCatalog::class),
                (string) $app['config']->get('ptadmin.addon.path', base_path('addons')),
                $app->make(TemplateDirectiveLoader::class),
                $app->make(RuntimeRegistry::class),
                $app->make(AddonAutoloader::class),
            );
        });
        $this->app->singleton(RuntimeLifecycleValidator::class);
    }

    private function registerAddonLifecycleServices(): void
    {
        $this->app->singleton(LifecycleRegistrar::class);
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
                $app->make(LifecycleRegistrar::class),
                $app->make(AddonAutoloader::class),
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
                $app->make(LifecycleRegistrar::class),
                $app->make('migrator'),
                $app->make(AddonAutoloader::class),
            );
        });
    }

    public function boot(): void
    {
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(
            [new \PTAdmin\Core\Http\Responses\AdminExceptionRenderer(), 'render'],
        );
        $this->app->make(HttpKernel::class)->prependMiddleware(\PTAdmin\Core\Http\Middleware\AdminApiMiddleware::class);
        $this->commands([\PTAdmin\Addon\Console\Commands\AddonCacheCommand::class]);
        // 缓存损坏时也必须能启动重建命令；该命令不执行任何插件 PHP 入口。
        $loadAddonRuntime = (bool) $this->app['config']->get('ptadmin.addon.runtime.enabled', true)
            && !($this->app->runningInConsole() && ($_SERVER['argv'][1] ?? null) === 'ptadmin:addon:cache');
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
        // 插件迁移必须在首次安装执行 migrate 前注册。
        if ($loadAddonRuntime) {
            $this->app->make(RuntimeRegistrar::class)->registerMigrations();
        }
        // 安装时也要同步内置 Schema；这里只注册描述符，不解析数据库连接。
        $this->registerAdminRuntimeBindings();
        // 主题加载失败不阻止 Core 启动；没有主题时不注册未命名空间的视图路径。
        $this->app->make(ThemeViewLoader::class)->load(
            $this->app->make(ActiveThemeResolver::class)->resolve(),
        );

        $engines = $this->app['view.engine.resolver'];
        $blade = $engines->resolve('blade');
        // Console 与 HTTP 内核可能在同一进程重复 boot，主题引擎只能包装一次 Blade 编译器。
        if (!$blade instanceof \PTAdmin\Template\Runtime\ThemeTemplateEngine) {
            $engines->register('blade', fn () => new \PTAdmin\Template\Runtime\ThemeTemplateEngine(
                $blade,
                $this->app->make(ThemeViewLoader::class),
                $this->app->make(TemplateRenderer::class),
            ));
        }

        if (!$isInstalled) {
            $this->app->make(HttpKernel::class)->prependMiddlewareToGroup('web', InstallStateMiddleware::class);
            $this->loadViewsFrom(__DIR__.'/../../../resources/views/install', 'ptadmin-install');
            $this->loadRoutesFrom(__DIR__.'/../../../routes/install.php');

            return;
        }

        $this->app['config']->set('mail.mailers.ptadmin', ['transport' => 'ptadmin']);
        $mailManager = $this->app->make('mail.manager');
        $mailManager->setDefaultDriver('ptadmin');
        $mailManager->extend('ptadmin', fn (): \Symfony\Component\Mailer\Transport\TransportInterface => $this->app->make(\PTAdmin\Core\Config\SystemMailTransport::class));
        $mailManager->purge('ptadmin');

        $this->app['router']->aliasMiddleware('ptadmin.session', AuthenticateSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.permission', RequirePermissionMiddleware::class);
        $this->app['router']->aliasMiddleware('ptadmin.audit', RecordAdminOperationMiddleware::class);
        $this->loadRoutesFrom(__DIR__.'/../../../routes/ptadmin.php');
        if ($loadAddonRuntime) {
            $runtimeResult = $this->app->make(RuntimeRegistrar::class)->register();
            foreach ($runtimeResult['errors'] as $error) {
                $this->app['log']->error('插件启动失败。', $error);
            }
        }
        if (!$this->app->runningInConsole()) {
            // 云交付、插件及主题文件任务均在 HTTP 响应结束后通过同一命令执行。
            $this->commands([TaskRunCommand::class]);
            return;
        }

        $this->commands([
            \PTAdmin\Cloud\Console\CloudCommand::class,
            \PTAdmin\Cloud\Console\PublishCommand::class,
            TaskRunCommand::class,
            TaskRecoverCommand::class,
            UnifiedSyncCommand::class,
            SchemaSyncCommand::class,
            SchemaPublishCommand::class,
            RuntimeCacheCommand::class,
            RuntimeValidateCommand::class,
            AdminSyncCommand::class,
            MenuSyncCommand::class,
            AddonSetupCommand::class,
        ]);
    }

    private function registerAdminRuntimeBindings(): void
    {
        if ($this->adminRuntimeBindingsRegistered) {
            return;
        }

        $this->adminRuntimeBindingsRegistered = true;
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
                ->hook('list.prepare', AdminRuntimeHook::class, 'prepareList')
                ->hook('create.prepare', AdminRuntimeHook::class, 'prepareCreate')
                ->hook('create.after', AdminRuntimeHook::class, 'created')
                ->hook('update.prepare', AdminRuntimeHook::class, 'prepareUpdate')
                ->hook('update.after', AdminRuntimeHook::class, 'updated')
                ->hook('delete.before', AdminRuntimeHook::class, 'deleting')
                ->hook('delete.after', AdminRuntimeHook::class, 'deleted')
                ->hook('purge.before', AdminRuntimeHook::class, 'deleting')
                ->hook('purge.after', AdminRuntimeHook::class, 'deleted')
                ->hook('output.transform', AdminRuntimeHook::class, 'output');
            foreach ($actions as $actionKey) {
                $resource->action($actionKey, AdminRuntimeHook::class, 'executeAction');
            }
        }
    }
}
