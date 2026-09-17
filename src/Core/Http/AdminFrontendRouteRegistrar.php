<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http;

use Illuminate\Support\Facades\Route;
use PTAdmin\Core\Http\Controllers\AdminFrontendController;

/**
 * 安装请求完成后把自定义后台前缀加入当前 Router，避免长驻 PHP 进程需要重启才可访问新入口。
 */
final class AdminFrontendRouteRegistrar
{
    /** @var array<string, true> */
    private array $registered = [];

    public function register(string $prefix): void
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '' || isset($this->registered[$prefix])) {
            return;
        }
        $this->registered[$prefix] = true;

        Route::prefix($prefix)->group(static function (): void {
            Route::get('/', [AdminFrontendController::class, 'index']);
            Route::get('{path}', [AdminFrontendController::class, 'index'])
                ->where('path', '^(?!assets(?:/|$)|modules(?:/|$)).*$');
        });
    }
}
