<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Core\Http\Controllers\InstallController as InstallApiController;
use PTAdmin\Core\Http\Controllers\InstallWizardController;

$apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
$installPath = trim((string) config('ptadmin.install.path', '__ptadmin__/install'), '/');

Route::middleware('ptadmin.request-id')
    ->post($apiPrefix.'/install', InstallApiController::class)
    ->name('ptadmin.install');

Route::prefix($installPath)->group(static function (): void {
    Route::get('/', [InstallWizardController::class, 'welcome'])->name('ptadmin.install.welcome');
    Route::post('accept', [InstallWizardController::class, 'accept'])->name('ptadmin.install.accept');
    Route::get('requirements', [InstallWizardController::class, 'requirements'])->name('ptadmin.install.requirements');
    Route::get('account', [InstallWizardController::class, 'account'])->name('ptadmin.install.account');
    Route::post('stream', [InstallWizardController::class, 'stream'])->name('ptadmin.install.stream');
});
