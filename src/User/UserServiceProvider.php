<?php

declare(strict_types=1);

namespace PTAdmin\User;

use Illuminate\Auth\Passwords\PasswordResetServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use PTAdmin\User\Contracts\UserIdentityInterface;
use PTAdmin\User\Events\UserUnavailable;

/** 注册身份服务和模型事件，认证配置由宿主 auth.php 提供，启动阶段不读取用户数据库。 */
final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(UserIdentityInterface::class, EloquentUserIdentity::class);
        $this->app->register(PasswordResetServiceProvider::class);
    }

    public function boot(): void
    {
        $identity = $this->app->make(UserIdentityInterface::class);
        if (!$identity instanceof EloquentUserIdentity) {
            return;
        }
        $model = $identity->model();
        $events = $this->app['events'];
        $model::updated(static function (Model $user) use ($events): void {
            if ($user->wasChanged('status') && !$user->getAttribute('status')) {
                $events->dispatch(new UserUnavailable((int) $user->getKey()));
            }
        });
        $model::deleted(static function (Model $user) use ($events): void {
            $events->dispatch(new UserUnavailable((int) $user->getKey()));
        });
    }
}
