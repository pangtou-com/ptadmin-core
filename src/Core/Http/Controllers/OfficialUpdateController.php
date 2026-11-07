<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;
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

    public function acknowledge(Request $request, OfficialUpdateService $updates): JsonResponse
    {
        return $this->respond($request, $updates, 'acknowledge');
    }

    private function respond(Request $request, OfficialUpdateService $updates, string $action): JsonResponse
    {
        $session = $request->attributes->get('ptadmin.session', []);
        $admin = Admin::query()->findOrFail($session['subject_id'] ?? null);
        $founder = (bool) $admin->is_founder && (bool) $admin->status;
        if ($action !== 'show' && !$founder) {
            return AdminResponse::error(40300, '只有创始人可以检查官方版本和确认安全公告，请联系创始人或部署负责人处理更新。', ['reason' => 'founder_required'], 403);
        }
        try {
            if ($action === 'check') {
                $updates->check();
            } elseif ($action === 'acknowledge') {
                $input = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
                $updates->acknowledge((int) $admin->id, $request->route('identifier'), (int) $input['revision']);
            }
            return AdminResponse::success('', $updates->overview((int) $admin->id, $founder))->header('Cache-Control', 'no-store');
        } catch (UpdateException $exception) {
            $status = in_array($exception->reason, ['check_running', 'check_cooldown', 'revision_conflict'], true) ? 409 : 503;
            return AdminResponse::error($status * 100, $exception->getMessage(), ['reason' => $exception->reason], $status)->header('Cache-Control', 'no-store');
        }
    }
}
