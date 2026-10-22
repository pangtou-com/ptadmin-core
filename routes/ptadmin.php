<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PTAdmin\Admin\Http\Controllers\AdminSessionController;
use PTAdmin\Admin\Http\Controllers\AssetController;
use PTAdmin\Admin\Http\Controllers\DashboardController;
use PTAdmin\Admin\Http\Controllers\DictionaryController;
use PTAdmin\Admin\Http\Controllers\LoginController;
use PTAdmin\Admin\Http\Controllers\NoticeInboxController;
use PTAdmin\Admin\Http\Controllers\NoticeTemplateController;
use PTAdmin\Admin\Http\Controllers\ProfileController;
use PTAdmin\Admin\Http\Controllers\PublicDictionaryController;
use PTAdmin\Core\Http\Controllers\AddonController;
use PTAdmin\Core\Http\Controllers\AddonTaskController;
use PTAdmin\Core\Http\Controllers\AdminFrontendController;
use PTAdmin\Core\Http\Controllers\BootstrapController;
use PTAdmin\Core\Http\Controllers\ConfigController;
use PTAdmin\Core\Http\Controllers\SessionController;
use PTAdmin\Core\Http\Controllers\TaskController;
use PTAdmin\Core\Http\Controllers\ThemeController;
use PTAdmin\Core\Http\Controllers\ThemeDevelopmentAssetController;
use PTAdmin\Cloud\Http\Controllers\CloudController;
use PTAdmin\Schema\Http\Controllers\PublishController;
use PTAdmin\Schema\Http\Controllers\ResourceController;
use PTAdmin\Schema\Http\Controllers\RuntimeController;

$apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
$authenticatedMiddleware = ['ptadmin.request-id', 'ptadmin.session', 'ptadmin.audit'];

Route::middleware('ptadmin.request-id')
    ->get($apiPrefix.'/bootstrap', BootstrapController::class)
    ->name('ptadmin.bootstrap');

Route::middleware('ptadmin.request-id')
    ->get($apiPrefix.'/public/dictionaries/{code}', [PublicDictionaryController::class, 'show'])
    ->where('code', '[a-z][a-z0-9._-]{0,189}')
    ->name('ptadmin.public.dictionaries.show');

Route::middleware('ptadmin.request-id')
    ->get($apiPrefix.'/public/site/themes/{code}/{version}/cover', [ThemeController::class, 'cover'])
    ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'version' => '\\d+\\.\\d+\\.\\d+(?:[-+][0-9A-Za-z.-]+)?'])
    ->name('ptadmin.public.site.themes.cover');

if ((bool) config('ptadmin.site_theme.development_assets.enabled', false)) {
    $developmentPrefix = trim((string) config('ptadmin.site_theme.development_assets.prefix', '__dev_theme__'), '/');
    Route::get($developmentPrefix.'/{identifier}/{path}', ThemeDevelopmentAssetController::class)
        ->where(['identifier' => '[A-Za-z][A-Za-z0-9._-]{1,63}@\\d+\\.\\d+\\.\\d+(?:[-+][0-9A-Za-z.-]+)?', 'path' => '.+'])
        ->name('ptadmin.dev.theme.asset');
}

Route::middleware('ptadmin.request-id')
    ->post($apiPrefix.'/login', LoginController::class)
    ->name('ptadmin.login');

Route::middleware('ptadmin.request-id')
    ->get($apiPrefix.'/cloud/session/callback', [CloudController::class, 'callback'])
    ->name('ptadmin.cloud.session.callback');

Route::middleware(['ptadmin.request-id', 'ptadmin.session'])
    ->group(static function () use ($apiPrefix): void {
        Route::get($apiPrefix.'/session', [SessionController::class, 'show'])
            ->name('ptadmin.session.show');
        Route::post($apiPrefix.'/session/renew', [SessionController::class, 'renew'])
            ->name('ptadmin.session.renew');
        Route::post($apiPrefix.'/session/revoke', [SessionController::class, 'revoke'])
            ->name('ptadmin.session.revoke');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/dashboard')
    ->as('ptadmin.dashboard.')
    ->group(static function (): void {
        Route::get('/', [DashboardController::class, 'show'])
            ->middleware('ptadmin.permission:dashboard,view')
            ->name('show');
        Route::post('application-sync', [DashboardController::class, 'synchronize'])
            ->middleware('ptadmin.permission:dashboard,view')
            ->name('application-sync');
        Route::get('me/widgets', [DashboardController::class, 'settings'])
            ->middleware('ptadmin.permission:dashboard,view')
            ->name('widgets.settings');
        Route::put('me/widgets', [DashboardController::class, 'saveSettings'])
            ->middleware('ptadmin.permission:admin.dashboard_widgets,update')
            ->name('widgets.save');
        Route::post('widgets/query', [DashboardController::class, 'query'])
            ->middleware('ptadmin.permission:dashboard,view')
            ->name('widgets.query');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/profile')
    ->as('ptadmin.profile.')
    ->group(static function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('password', [ProfileController::class, 'password'])->name('password');
        Route::get('login-logs', [ProfileController::class, 'loginLogs'])->name('login-logs');
        Route::get('operations', [ProfileController::class, 'operationLogs'])->name('operations');
        Route::get('operations/{id}', [ProfileController::class, 'operationDetail'])
            ->whereNumber('id')
            ->name('operations.show');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/sessions')
    ->as('ptadmin.sessions.')
    ->group(static function (): void {
        Route::get('/', [AdminSessionController::class, 'index'])->name('index');
        Route::delete('{sessionId}', [AdminSessionController::class, 'revoke'])
            ->where('sessionId', '[A-Za-z0-9]{32}')
            ->name('revoke');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/assets')
    ->as('ptadmin.assets.')
    ->group(static function (): void {
        Route::get('/', [AssetController::class, 'index'])
            ->middleware('ptadmin.permission:assets,view')
            ->name('index');
        Route::get('{id}', [AssetController::class, 'show'])
            ->whereNumber('id')
            ->middleware('ptadmin.permission:assets,view')
            ->name('show');
        Route::patch('{id}', [AssetController::class, 'update'])
            ->whereNumber('id')
            ->middleware('ptadmin.permission:assets,update')
            ->name('update');
        Route::delete('{id}', [AssetController::class, 'archive'])
            ->whereNumber('id')
            ->middleware('ptadmin.permission:assets,delete')
            ->name('archive');
        Route::post('{id}/restore', [AssetController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('ptadmin.permission:assets,restore')
            ->name('restore');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/notices')
    ->as('ptadmin.notices.')
    ->group(static function (): void {
        Route::get('/', [NoticeInboxController::class, 'index'])->name('index');
        Route::get('unread-count', [NoticeInboxController::class, 'unreadCount'])->name('unread-count');
        Route::patch('read-all', [NoticeInboxController::class, 'markAllRead'])->name('read-all');
        Route::patch('{id}/read', [NoticeInboxController::class, 'markRead'])->whereNumber('id')->name('read');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/cloud')
    ->as('ptadmin.cloud.')
    ->group(static function (): void {
        Route::get('session', [CloudController::class, 'session'])->name('session');
        Route::get('session/authorize', [CloudController::class, 'authorize'])->name('session.authorize');
        Route::post('session/logout', [CloudController::class, 'logout'])->name('session.logout');
        foreach (['code-options', 'options', 'redeem', 'project', 'bind', 'release', 'package'] as $operation) {
            Route::post('delivery/'.$operation, \PTAdmin\Cloud\Http\Controllers\ProductDeliveryController::class)->defaults('operation', $operation);
        }
        Route::get('delivery/tasks/{taskId}/package', \PTAdmin\Cloud\Http\Controllers\ProductDeliveryController::class)->defaults('operation', 'artifact')->whereUuid('taskId');
        Route::get('catalog', [CloudController::class, 'catalog'])->name('catalog');
        Route::get('developer/products', [CloudController::class, 'products'])->name('developer.products');
        Route::post('developer/{type}/releases/package', [CloudController::class, 'submitRelease'])->where('type', 'addon|template')->name('developer.release.submit');
        Route::post('orders', [CloudController::class, 'order'])->name('orders.store');
        Route::get('orders/{orderId}', [CloudController::class, 'showOrder'])->name('orders.show');
        Route::post('licenses/preview', [CloudController::class, 'license'])->name('licenses.preview');
        Route::post('licenses/migrate-ip', [CloudController::class, 'migrateIp'])->name('licenses.migrate-ip');
        Route::post('licenses/migration-requests', [CloudController::class, 'requestMigration'])->name('licenses.migration-requests.store');
    });

Route::middleware($authenticatedMiddleware)
    ->get($apiPrefix.'/notice-templates/options', [NoticeTemplateController::class, 'options'])
    ->middleware('ptadmin.permission:admin.notification_templates,view')
    ->name('ptadmin.notice-templates.options');

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/dictionaries')
    ->as('ptadmin.dictionaries.')
    ->group(static function (): void {
        Route::get('/', [DictionaryController::class, 'index'])
            ->middleware('ptadmin.permission:admin.dictionaries,view')->name('index');
        Route::post('/', [DictionaryController::class, 'store'])
            ->middleware('ptadmin.permission:admin.dictionaries,create')->name('store');
        Route::get('{code}', [DictionaryController::class, 'show'])
            ->middleware('ptadmin.permission:admin.dictionaries,view')->name('show');
        Route::put('{code}', [DictionaryController::class, 'update'])
            ->middleware('ptadmin.permission:admin.dictionaries,update')->name('update');
        Route::delete('{code}', [DictionaryController::class, 'destroy'])
            ->middleware('ptadmin.permission:admin.dictionaries,delete')->name('destroy');
        Route::get('{code}/entries', [DictionaryController::class, 'entries'])
            ->middleware('ptadmin.permission:admin.dictionaries,view')->name('entries.index');
        Route::post('{code}/entries', [DictionaryController::class, 'storeEntry'])
            ->middleware('ptadmin.permission:admin.dictionaries,create')->name('entries.store');
        Route::put('{code}/entries/{id}', [DictionaryController::class, 'updateEntry'])
            ->middleware('ptadmin.permission:admin.dictionaries,update')->whereNumber('id')->name('entries.update');
        Route::delete('{code}/entries/{id}', [DictionaryController::class, 'destroyEntry'])
            ->middleware('ptadmin.permission:admin.dictionaries,delete')->whereNumber('id')->name('entries.destroy');
    });

Route::middleware($authenticatedMiddleware)
    ->post($apiPrefix.'/storage/upload', [AssetController::class, 'upload'])
    ->middleware('ptadmin.permission:assets,upload')
    ->name('ptadmin.storage.upload');

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/config')
    ->as('ptadmin.config.')
    ->group(static function (): void {
        Route::post('mail/test', [ConfigController::class, 'testMail'])
            ->middleware(['ptadmin.permission:admin.config,update', 'throttle:5,1'])
            ->name('mail.test');
        Route::get('definitions', [ConfigController::class, 'definitions'])
            ->middleware('ptadmin.permission:admin.config,view')
            ->name('definitions');
        Route::post('definitions', [ConfigController::class, 'store'])
            ->middleware('ptadmin.permission:admin.config,design')
            ->name('definitions.store');
        Route::delete('definitions/{config_key}', [ConfigController::class, 'destroy'])
            ->where('config_key', '[a-z][a-z0-9_.-]{0,190}')
            ->middleware('ptadmin.permission:admin.config,design')
            ->name('definitions.destroy');
        Route::get('{owner_type}/{owner_code}/{config_key}', [ConfigController::class, 'show'])
            ->where(['owner_type' => '[a-z]+', 'owner_code' => '[^/]+', 'config_key' => '[^/]+'])
            ->middleware('ptadmin.permission:admin.config,view')
            ->name('show');
        Route::put('{owner_type}/{owner_code}/{config_key}', [ConfigController::class, 'update'])
            ->where(['owner_type' => '[a-z]+', 'owner_code' => '[^/]+', 'config_key' => '[^/]+'])
            ->middleware('ptadmin.permission:admin.config,update')
            ->name('update');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/addons')
    ->as('ptadmin.addons.')
    ->group(static function (): void {
        Route::get('{code}/configuration', [ConfigController::class, 'addonDefinitions'])
            ->middleware('ptadmin.permission:addon.manage,view')
            ->where('code', '[A-Za-z][A-Za-z0-9._-]{1,63}')
            ->name('configuration.index');
        Route::get('{code}/configuration/{config_key}', [ConfigController::class, 'addonShow'])
            ->middleware('ptadmin.permission:addon.manage,view')
            ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'config_key' => '[a-z][a-z0-9_.-]{0,190}'])
            ->name('configuration.show');
        Route::patch('{code}/configuration/{config_key}', [ConfigController::class, 'addonUpdate'])
            ->middleware('ptadmin.permission:addon.manage,update')
            ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'config_key' => '[a-z][a-z0-9_.-]{0,190}'])
            ->name('configuration.update');
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

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/site/themes')
    ->as('ptadmin.site.themes.')
    ->group(static function (): void {
        Route::get('/', [ThemeController::class, 'index'])
            ->middleware('ptadmin.permission:site.theme,read')
            ->name('index');
        Route::post('/install', [ThemeController::class, 'install'])
            ->middleware('ptadmin.permission:site.theme,install')
            ->name('install');
        Route::get('/{code}/{version}', [ThemeController::class, 'show'])
            ->middleware('ptadmin.permission:site.theme,read')
            ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'version' => '\\d+\\.\\d+\\.\\d+(?:[-+][0-9A-Za-z.-]+)?'])
            ->name('show');
        Route::post('/{code}/{version}/activate', [ThemeController::class, 'activate'])
            ->middleware('ptadmin.permission:site.theme,activate')
            ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'version' => '\\d+\\.\\d+\\.\d+(?:[-+][0-9A-Za-z.-]+)?'])
            ->name('activate');
        Route::delete('/{code}/{version}', [ThemeController::class, 'remove'])
            ->middleware('ptadmin.permission:site.theme,remove')
            ->where(['code' => '[A-Za-z][A-Za-z0-9._-]{1,63}', 'version' => '\\d+\\.\\d+\\.\d+(?:[-+][0-9A-Za-z.-]+)?'])
            ->name('remove');
    });

Route::middleware($authenticatedMiddleware)
    ->prefix($apiPrefix.'/tasks')
    ->as('ptadmin.tasks.')
    ->group(static function (): void {
        Route::get('/', [TaskController::class, 'list'])
            ->middleware('ptadmin.permission:core.tasks,view')
            ->name('list');
        Route::post('/', [TaskController::class, 'create'])->name('create');
        Route::get('{task_id}', [TaskController::class, 'show'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('show');
        Route::get('{task_id}/events', [TaskController::class, 'events'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('events');
        Route::get('{task_id}/stream', [TaskController::class, 'stream'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('stream');
        Route::post('{task_id}/retry', [TaskController::class, 'retry'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('retry');
        Route::post('{task_id}/cancel', [TaskController::class, 'cancel'])
            ->where('task_id', '[A-Za-z0-9][A-Za-z0-9._-]{7,127}')
            ->name('cancel');
    });

$schemaPrefix = trim((string) config('ptadmin-schema.routes.prefix', 'ptadmin/schema'), '/');
$schemaMiddleware = array_merge($authenticatedMiddleware, (array) config('ptadmin-schema.routes.middleware', ['api']));
$schemaNamePrefix = (string) config('ptadmin-schema.routes.name_prefix', 'ptadmin.schema.');

Route::middleware($schemaMiddleware)
    ->prefix($schemaPrefix)
    ->as($schemaNamePrefix)
    ->group(static function (): void {
        Route::get('options', [ResourceController::class, 'options'])
            ->middleware('ptadmin.permission:schema.resources,view')
            ->name('options.index');
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

        Route::post('runtime', [RuntimeController::class, 'dispatch'])->name('runtime.dispatch');
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

$webPrefix = trim((string) config('ptadmin.web_prefix', 'admin'), '/');
Route::prefix($webPrefix)->group(static function (): void {
    Route::get('/', [AdminFrontendController::class, 'index'])->name('ptadmin.web.index');
    Route::get('{path}', [AdminFrontendController::class, 'index'])
        ->where('path', '^(?!assets(?:/|$)|modules(?:/|$)).*$')
        ->name('ptadmin.web.spa');
});

Route::prefix('admin_{suffix}')->group(static function (): void {
    Route::get('/', [AdminFrontendController::class, 'index'])
        ->where('suffix', '[a-f0-9]{12}');
    Route::get('{path}', [AdminFrontendController::class, 'index'])
        ->where([
            'suffix' => '[a-f0-9]{12}',
            'path' => '^(?!assets(?:/|$)|modules(?:/|$)).*$',
        ]);
});
