<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Core\Http\Controllers\ConfigController;
use PTAdmin\Core\Http\Controllers\TaskController;
use PTAdmin\Core\Http\Controllers\AddonController;
use PTAdmin\Core\Http\Controllers\AddonTaskController;
use PTAdmin\Admin\Http\Controllers\ProfileController;
use PTAdmin\Admin\Http\Controllers\AdminSessionController;

$apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');

Route::middleware(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'])
    ->prefix($apiPrefix.'/profile')
    ->as('ptadmin.profile.')
    ->group(static function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('password', [ProfileController::class, 'password'])->name('password');
    });

Route::middleware(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'])
    ->prefix($apiPrefix.'/sessions')
    ->as('ptadmin.sessions.')
    ->group(static function (): void {
        Route::get('/', [AdminSessionController::class, 'index'])->name('index');
        Route::delete('{sessionId}', [AdminSessionController::class, 'revoke'])->where('sessionId', '[A-Za-z0-9]{32}')->name('revoke');
    });

Route::middleware(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'])
    ->prefix($apiPrefix.'/config')
    ->as('ptadmin.config.')
    ->group(static function (): void {
        Route::get('definitions', [ConfigController::class, 'definitions'])
            ->middleware('ptadmin.permission:admin.config,view')
            ->name('definitions');
        Route::get('{owner_type}/{owner_code}/{config_key}', [ConfigController::class, 'show'])
            ->where(['owner_type' => '[a-z]+', 'owner_code' => '[^/]+', 'config_key' => '[^/]+'])
            ->middleware('ptadmin.permission:admin.config,view')
            ->name('show');
        Route::put('{owner_type}/{owner_code}/{config_key}', [ConfigController::class, 'update'])
            ->where(['owner_type' => '[a-z]+', 'owner_code' => '[^/]+', 'config_key' => '[^/]+'])
            ->middleware('ptadmin.permission:admin.config,update')
            ->name('update');
    });

Route::middleware(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'])
    ->prefix($apiPrefix.'/addons')
    ->as('ptadmin.addons.')
    ->group(static function (): void {
        Route::get('/', [AddonController::class, 'index'])
            ->middleware('ptadmin.permission:addon.manage,view')
            ->name('index');
        Route::post('download', [AddonTaskController::class, 'download'])
            ->middleware('ptadmin.permission:addon.download,create')
            ->name('download');
        Route::post('{code}/install', [AddonTaskController::class, 'install'])
            ->middleware('ptadmin.permission:addon.install,create')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('install');
        Route::post('{code}/update', [AddonTaskController::class, 'update'])
            ->middleware('ptadmin.permission:addon.update,update')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('update');
        Route::post('{code}/uninstall', [AddonTaskController::class, 'uninstall'])
            ->middleware('ptadmin.permission:addon.uninstall,delete')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('uninstall');
        Route::post('{code}/frontend-sync', [AddonTaskController::class, 'frontendSync'])
            ->middleware('ptadmin.permission:addon.frontend,update')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('frontend-sync');
        Route::post('{code}/enable', [AddonTaskController::class, 'enable'])
            ->middleware('ptadmin.permission:addon.enable,update')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('enable');
        Route::post('{code}/disable', [AddonTaskController::class, 'disable'])
            ->middleware('ptadmin.permission:addon.disable,update')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('disable');
    });

Route::middleware(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'])
    ->prefix($apiPrefix.'/tasks')
    ->as('ptadmin.tasks.')
    ->group(static function (): void {
        Route::get('/', [TaskController::class, 'list'])
            ->middleware('ptadmin.permission:core.tasks,view')
            ->name('list');
        Route::post('/', [TaskController::class, 'create'])
            ->name('create');
        Route::get('{task_id}', [TaskController::class, 'show'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('show');
        Route::get('{task_id}/events', [TaskController::class, 'events'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('events');
        Route::post('{task_id}/retry', [TaskController::class, 'retry'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('retry');
        Route::post('{task_id}/cancel', [TaskController::class, 'cancel'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('cancel');
    });
