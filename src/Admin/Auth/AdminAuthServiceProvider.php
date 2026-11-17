<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Support\ServiceProvider;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Auth\Session\SessionRevoked;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;

/** 注册驱动和权限桥接，不改写宿主 auth 配置或提前解析业务数据库。 */
final class AdminAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdminAuth::class, static fn ($app): AdminAuth => new AdminAuth(
            $app->make(Factory::class),
            static fn (): AuthorizationService => $app->make(AuthorizationService::class),
        ));
    }

    public function boot(): void
    {
        $this->callAfterResolving('auth', function (AuthManager $auth): void {
            $auth->extend('ptadmin-session', static function ($app): AdminSessionGuard {
                $guard = new AdminSessionGuard($app['request'], $app->make(InstallStateStore::class),
                    static fn (): SessionManager => $app->make(SessionManager::class));
                $app->refresh('request', $guard, 'setRequest');
                return $guard;
            });
        });
        $this->callAfterResolving(Gate::class, function (Gate $gate): void {
            $gate->define('ptadmin.permission', function ($user, string $permission): bool {
                [$resource, $ability] = PermissionName::parse($permission);
                if (!$user instanceof Admin || !$user->status || $user->trashed()
                    || !$this->app->make(InstallStateStore::class)->isInstalled()) {
                    return false;
                }
                return $this->app->make(AuthorizationService::class)->allows($user, $ability, $resource);
            });
        });
        $this->app['events']->listen(SessionRevoked::class, function (SessionRevoked $event): void {
            $guard = $this->app['request']->attributes->get(AdminSessionGuard::class);
            if ($guard instanceof AdminSessionGuard && $guard->invalidate($event)) {
                $this->app->make(SchemaSubjectContextInterface::class)->set(null);
            }
        });
    }
}
