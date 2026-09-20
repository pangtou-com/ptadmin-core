<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Core\Http\Controllers\InstallController as InstallApiController;
use PTAdmin\Core\Http\Controllers\InstallStatusController;
use PTAdmin\Core\Http\Controllers\InstallWizardController;

Route::middleware('ptadmin.install')->prefix('__ptadmin__/install')->group(static function (): void {
    Route::get('/', [InstallWizardController::class, 'welcome'])->name('ptadmin.install.welcome');
    Route::middleware('ptadmin.request-id')->group(static function (): void {
        Route::get('status', InstallStatusController::class)->name('ptadmin.install.status');
        Route::post('/', InstallApiController::class)->name('ptadmin.install');
    });
    Route::post('accept', [InstallWizardController::class, 'accept'])->name('ptadmin.install.accept');
    Route::get('requirements', [InstallWizardController::class, 'requirements'])->name('ptadmin.install.requirements');
    Route::get('account', [InstallWizardController::class, 'account'])->name('ptadmin.install.account');
    Route::post('stream', [InstallWizardController::class, 'stream'])->name('ptadmin.install.stream');
});
