<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Core\Http\Controllers\AdminFrontendController;

Route::prefix(trim((string) config('ptadmin.web_prefix', 'admin'), '/'))->group(static function (): void {
    Route::get('/', [AdminFrontendController::class, 'index'])->name('ptadmin.web.index');
    Route::get('{path}', [AdminFrontendController::class, 'index'])
        ->where('path', '^(?!assets(?:/|$)|modules(?:/|$)).*$')
        ->name('ptadmin.web.spa');
});

// 随机前缀在安装完成时才生成；该路由保证当前进程无需重启即可进入新后台地址。
Route::prefix('admin_{suffix}')->group(static function (): void {
    Route::get('/', [AdminFrontendController::class, 'index'])
        ->where('suffix', '[a-f0-9]{12}');
    Route::get('{path}', [AdminFrontendController::class, 'index'])
        ->where([
            'suffix' => '[a-f0-9]{12}',
            'path' => '^(?!assets(?:/|$)|modules(?:/|$)).*$',
        ]);
});
