<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Updates\OfficialUpdateService;
use PTAdmin\Core\Updates\UpdateException;

final class OfficialUpdateController
{
    public function show(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        return $this->respond($request, $updates, 'show');
    }

    public function check(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        return $this->respond($request, $updates, 'check');
    }

    public function checkOnLogin(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        $session = $request->attributes->get('ptadmin.session', []);
        $admin = Admin::query()->findOrFail($session['subject_id'] ?? null);
        if (!$admin->status || !$admin->is_founder || !app(AuthorizationService::class)->allows($admin, 'manage', 'system.updates')) {
            return AdminResponse::error(40300, '仅有版本检查权限的创始人可检查更新。', null, 403);
        }
        try {
            if ($updates->checkOnLogin($session['id'], (new \DateTimeImmutable($session['absolute_expires_at'], new \DateTimeZone('UTC')))->getTimestamp())) {
                return $this->respond($request, $updates, 'show');
            }
        } catch (\Throwable) {
            // 自动检查不把离线、缓存或环境问题传播到登录后的正常使用流程。
        }
        return AdminResponse::success('', null)->header('Cache-Control', 'no-store');
    }

    public function dismissReminder(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        return $this->respond($request, $updates, 'dismiss');
    }

    public function acknowledge(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        return $this->respond($request, $updates, 'acknowledge');
    }

    public function install(Request $request, \PTAdmin\Core\Updates\OfficialUpdateInstaller $installer, \PTAdmin\Core\Tasks\PendingTaskDispatcher $dispatcher): JsonResponse
    {
        $session = $request->attributes->get('ptadmin.session', []);
        $admin = Admin::query()->findOrFail($session['subject_id'] ?? null);
        $input = $request->validate(['component' => ['required', 'in:core,admin-shell'],
            'version' => ['required', 'string', 'max:80'], 'database_backup_confirmed' => ['sometimes', 'boolean']]);
        $resource = $input['component'] === 'core' ? 'core.update' : 'admin.frontend';
        if (!$admin->status || !$admin->is_founder || !app(AuthorizationService::class)->allows($admin, 'create', $resource)) {
            return AdminResponse::error(40300, '当前账号没有该组件的更新权限。', null, 403);
        }
        try {
            $task = $installer->submit($input['component'], $input['version'], (int) $admin->id,
                $request->attributes->get('ptadmin.request_id'), $input['database_backup_confirmed'] ?? false);
            $dispatcher->dispatch($task);
            return AdminResponse::success('', $task, 201);
        } catch (UpdateException $error) {
            return AdminResponse::error(42200, $error->getMessage(), ['reason' => $error->reason], 422);
        } catch (\PTAdmin\Core\Tasks\TaskException $error) {
            return AdminResponse::error(40900, $error->getMessage(), ['error_code' => $error->errorCode, 'details' => $error->details], 409);
        }
    }

    private function respond(Request $request, OfficialUpdateService $updates, string $action): JsonResponse
    {
        $session = $request->attributes->get('ptadmin.session', []);
        $admin = Admin::query()->findOrFail($session['subject_id'] ?? null);
        $showUpdates = (bool) $admin->status && (bool) $admin->is_founder;
        $canManage = $showUpdates && app(AuthorizationService::class)->allows($admin, 'manage', 'system.updates');
        if ($action !== 'show' && !$canManage) {
            return AdminResponse::error(40300, '当前账号没有版本检查和安全公告管理权限。', ['reason' => 'permission_required'], 403);
        }
        try {
            if ($action === 'check') {
                $updates->check();
            } elseif ($action === 'dismiss') {
                $input = $request->validate(['mode' => ['required', 'in:session,version'],
                    'component' => ['required_if:mode,version', 'in:core,admin-shell'],
                    'version' => ['required_if:mode,version', 'string', 'max:80']]);
                $expiresAt = (new \DateTimeImmutable($session['absolute_expires_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $updates->dismissReminder((int) $admin->id, $session['id'], $expiresAt, $input['mode'], $input['component'] ?? null, $input['version'] ?? null);
            } elseif ($action === 'acknowledge') {
                $input = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
                $updates->acknowledge((int) $admin->id, $request->route('identifier'), (int) $input['revision']);
            }
            return AdminResponse::success('', $updates->overview((int) $admin->id, $canManage, [
                'core' => $showUpdates && app(AuthorizationService::class)->allows($admin, 'create', 'core.update'),
                'admin-shell' => $showUpdates && app(AuthorizationService::class)->allows($admin, 'create', 'admin.frontend'),
            ], $showUpdates, $session['id']))->header('Cache-Control', 'no-store');
        } catch (UpdateException $exception) {
            $status = in_array($exception->reason, ['check_running', 'check_cooldown', 'revision_conflict'], true) ? 409 : 503;
            return AdminResponse::error($status * 100, $exception->getMessage(), ['reason' => $exception->reason], $status)->header('Cache-Control', 'no-store');
        }
    }
}
