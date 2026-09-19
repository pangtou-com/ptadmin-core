<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Providers;

use Illuminate\Support\ServiceProvider;
use PTAdmin\Schema\Meta\Service\ResourceService;
use PTAdmin\Schema\Meta\Service\ResourceLocator;
use PTAdmin\Schema\Publish\Service\PublishService;
use PTAdmin\Schema\Runtime\RuntimeEngine;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeResourceHookRegistry;
use PTAdmin\Schema\Runtime\RecordTransferService;
use PTAdmin\Schema\Runtime\TreeService;
use PTAdmin\Schema\Audit\Service\AuditWriter;
use PTAdmin\Schema\Definition\DefinitionService;
use PTAdmin\Schema\Support\SchemaManager;
use PTAdmin\Schema\Support\SchemaSubjectContext;

/**
 * 注册 Schema 领域服务、协议接口别名和元数据迁移。
 */
final class SchemaServiceProvider extends ServiceProvider
{
    /**
     * 以单例方式绑定服务，保证宿主注入的执行器和发布服务在一次请求内保持一致。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/ptadmin-schema.php', 'ptadmin-schema');
        $this->app->singleton(SchemaManager::class);
        $this->app->scoped(SchemaSubjectContext::class);
        $this->app->alias(SchemaSubjectContext::class, \PTAdmin\Schema\Contracts\SchemaSubjectContextInterface::class);
        $this->app->alias(SchemaManager::class, 'ptadmin.schema');
        $this->app->singleton(ResourceService::class);
        $this->app->singleton(ResourceLocator::class);
        $this->app->singleton(PublishService::class);
        $this->app->singleton(RuntimeResourceHookRegistry::class);
        $this->app->singleton(RuntimeRegistry::class);
        $this->app->singleton(RuntimeCacheStore::class, static function ($app): RuntimeCacheStore {
            $path = config('ptadmin-schema.runtime.cache_path');
            if (!is_string($path) || $path === '') {
                $path = $app->basePath('bootstrap/cache/ptadmin-runtime.php');
            }

            return new RuntimeCacheStore($path);
        });
        $this->app->singleton(RuntimeEngine::class);
        $this->app->singleton(TreeService::class);
        $this->app->singleton(RecordTransferService::class);
        $this->app->singleton(AuditWriter::class);
        $this->app->singleton(DefinitionService::class);
        $this->app->alias(ResourceService::class, \PTAdmin\Schema\Contracts\ResourceServiceInterface::class);
        $this->app->alias(PublishService::class, \PTAdmin\Schema\Contracts\PublishServiceInterface::class);
        $this->app->alias(RuntimeEngine::class, \PTAdmin\Schema\Contracts\RuntimeEngineInterface::class);
        $this->app->alias(TreeService::class, \PTAdmin\Schema\Contracts\TreeServiceInterface::class);
        $this->app->alias(RecordTransferService::class, \PTAdmin\Schema\Contracts\RecordTransferServiceInterface::class);
        $this->app->alias(DefinitionService::class, \PTAdmin\Schema\Contracts\DefinitionServiceInterface::class);
    }

    /**
     * 发布配置并加载包内迁移；迁移由宿主 Laravel 应用负责执行。
     */
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../../config/ptadmin-schema.php' => config_path('ptadmin-schema.php')], 'ptadmin-schema-config');
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../../routes/schema.php');
    }
}
