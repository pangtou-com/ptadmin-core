<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Schema\Http\Controllers\PublishController;
use PTAdmin\Schema\Http\Controllers\ResourceController;
use PTAdmin\Schema\Http\Controllers\RuntimeController;

$prefix = trim((string) config('ptadmin-schema.routes.prefix', 'ptadmin/schema'), '/');
$middleware = config('ptadmin-schema.routes.middleware', ['api']);
$namePrefix = (string) config('ptadmin-schema.routes.name_prefix', 'ptadmin.schema.');

Route::middleware(array_merge(['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'], (array) $middleware))
    ->prefix($prefix)
    ->as($namePrefix)
    ->group(static function (): void {
        Route::get('resources', [ResourceController::class, 'index'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->name('resources.index');
        Route::post('resources', [ResourceController::class, 'store'])
            ->middleware('ptadmin.permission:schema.resources,create')
            ->name('resources.store');
        Route::get('resources/{resource}', [ResourceController::class, 'show'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where('resource', '[^/]+')
            ->name('resources.show');
        Route::delete('resources/{resource}/draft', [ResourceController::class, 'deleteDraft'])
            ->middleware('ptadmin.permission:schema.resources,delete')
            ->where('resource', '[^/]+')
            ->name('resources.draft.delete');
        Route::get('resources/{resource}/draft', [ResourceController::class, 'draft'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where('resource', '[^/]+')
            ->name('resources.draft.show');
        Route::post('resources/{resource}/draft/edit', [ResourceController::class, 'beginDraftEdit'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.draft.edit');
        Route::put('resources/{resource}/draft', [ResourceController::class, 'saveDraft'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.draft.update');
        Route::post('resources/{resource}/sync', [ResourceController::class, 'syncCode'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.sync');
        Route::get('resources/{resource}/active-version', [ResourceController::class, 'activeVersion'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where('resource', '[^/]+')
            ->name('resources.active-version');
        Route::get('resources/{resource}/versions', [ResourceController::class, 'versions'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where('resource', '[^/]+')
            ->name('resources.versions.index');
        Route::get('resources/{resource}/versions/{version}', [ResourceController::class, 'version'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where(['resource' => '[^/]+', 'version' => '[0-9]+'])
            ->name('resources.versions.show');
        Route::post('resources/{resource}/enable', [ResourceController::class, 'enable'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.enable');
        Route::post('resources/{resource}/disable', [ResourceController::class, 'disable'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.disable');
        Route::post('resources/{resource}/archive', [ResourceController::class, 'archive'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.archive');
        Route::post('resources/{resource}/projection/rebuild', [ResourceController::class, 'rebuildProjection'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.projection.rebuild');

        Route::post('resources/{resource}/check', [PublishController::class, 'check'])
            ->middleware('ptadmin.permission:schema.resources,update')
            ->where('resource', '[^/]+')
            ->name('resources.check');
        Route::get('resources/{resource}/approval', [PublishController::class, 'approval'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->where('resource', '[^/]+')
            ->name('resources.approval');
        Route::post('resources/{resource}/approve', [PublishController::class, 'approve'])
            ->middleware('ptadmin.permission:schema.resources,publish')
            ->where('resource', '[^/]+')
            ->name('resources.approve');
        Route::post('resources/{resource}/publish', [PublishController::class, 'publish'])
            ->middleware('ptadmin.permission:schema.resources,publish')
            ->where('resource', '[^/]+')
            ->name('resources.publish');
        Route::get('publish-tasks/{task}', [PublishController::class, 'task'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->whereNumber('task')
            ->name('publish-tasks.show');

        Route::post('runtime', [RuntimeController::class, 'dispatch'])
            ->name('runtime.dispatch');
        Route::get('runtime/{resource}/schema', [RuntimeController::class, 'schema'])
            ->where('resource', '[^/]+')
            ->name('runtime.schema');
        Route::get('runtime/{resource}/records', [RuntimeController::class, 'list'])
            ->where('resource', '[^/]+')
            ->name('runtime.records.index');
        Route::post('runtime/{resource}/records', [RuntimeController::class, 'create'])
            ->where('resource', '[^/]+')
            ->name('runtime.records.store');
        Route::get('runtime/{resource}/records/{id}', [RuntimeController::class, 'detail'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.show');
        Route::put('runtime/{resource}/records/{id}', [RuntimeController::class, 'update'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.update');
        Route::delete('runtime/{resource}/records/{id}', [RuntimeController::class, 'delete'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.destroy');
        Route::post('runtime/{resource}/records/{id}/restore', [RuntimeController::class, 'restore'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.restore');
        Route::delete('runtime/{resource}/records/{id}/purge', [RuntimeController::class, 'purge'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.purge');
        Route::post('runtime/{resource}/records/{id}/action', [RuntimeController::class, 'action'])
            ->where(['resource' => '[^/]+', 'id' => '[0-9]+'])
            ->name('runtime.records.action');
        Route::get('runtime/{resource}/relations', [RuntimeController::class, 'relationCandidates'])
            ->where('resource', '[^/]+')
            ->name('runtime.relations.index');
    });
