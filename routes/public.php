<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Admin\Http\Controllers\LoginController;
use PTAdmin\Core\Http\Controllers\BootstrapController;
use PTAdmin\Core\Http\Controllers\SessionController;
use PTAdmin\Core\Http\Controllers\InstallStatusController;
use PTAdmin\Core\Install\InstallStateStore;

$apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');

Route::middleware('ptadmin.request-id')
    ->get($apiPrefix.'/bootstrap', BootstrapController::class)
    ->name('ptadmin.bootstrap');

Route::middleware('ptadmin.request-id')
    ->post($apiPrefix.'/login', LoginController::class)
    ->name('ptadmin.login');

if (!app(InstallStateStore::class)->isInstalled()) {
    Route::middleware('ptadmin.request-id')
        ->get($apiPrefix.'/install/status', InstallStatusController::class)
        ->name('ptadmin.install.status');
}

Route::middleware(['ptadmin.request-id', 'ptadmin.session'])
    ->group(static function () use ($apiPrefix): void {
        Route::get($apiPrefix.'/session', [SessionController::class, 'show'])
            ->name('ptadmin.session.show');
        Route::post($apiPrefix.'/session/renew', [SessionController::class, 'renew'])
            ->name('ptadmin.session.renew');
        Route::post($apiPrefix.'/session/revoke', [SessionController::class, 'revoke'])
            ->name('ptadmin.session.revoke');
    });
