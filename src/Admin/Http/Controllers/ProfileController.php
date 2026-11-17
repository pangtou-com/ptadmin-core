<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Facades\AdminAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Profile\ProfileAuditService;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class ProfileController extends Controller
{
    public function __construct(
        private AdminAuthenticator $authenticator,
        private SessionManager $sessions,
        private ProfileAuditService $audit,
    )
    {
    }

    public function show(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->authenticator->userPayload(AdminAuth::authenticate()));
    }

    public function update(Request $request): JsonResponse
    {
        $admin = AdminAuth::authenticate();
        try {
            $data = $request->validate([
                'nickname' => ['required', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255', Rule::unique('admins', 'email')->whereNull('deleted_at')->ignore($admin->getKey())],
                'mobile' => ['nullable', 'string', 'max:30', Rule::unique('admins', 'mobile')->whereNull('deleted_at')->ignore($admin->getKey())],
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(42200, '个人资料校验失败。', $exception->errors(), 422);
        }

        $admin->forceFill($data)->save();

        return AdminResponse::success('', $this->authenticator->userPayload($admin));
    }

    public function password(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:6', 'max:36', 'confirmed'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(42200, '密码信息校验失败。', $exception->errors(), 422);
        }

        $admin = AdminAuth::authenticate();
        if (!Hash::check((string) $data['current_password'], (string) $admin->password)) {
            return AdminResponse::error(42201, '当前密码不正确。', ['field' => 'current_password'], 422);
        }
        $admin->forceFill(['password' => Hash::make((string) $data['password'])])->save();
        $revoked = $this->sessions->revokeSubject(Admin::class, (int) $admin->getKey(), 'admin');

        return AdminResponse::success('', ['revoked_sessions' => $revoked]);
    }

    public function loginLogs(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->audit->loginLogs(
            (int) AdminAuth::authenticate()->getKey(),
            $request->integer('page', 1),
            $request->integer('limit', 20),
        ));
    }

    public function operationLogs(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->audit->operationLogs(
            (int) AdminAuth::authenticate()->getKey(),
            $request->integer('page', 1),
            $request->integer('limit', 20),
        ));
    }

    public function operationDetail(Request $request, int $id): JsonResponse
    {
        $operation = $this->audit->operation((int) AdminAuth::authenticate()->getKey(), $id);
        if ($operation === null) {
            return AdminResponse::error(40400, '操作记录不存在。', null, 404);
        }

        return AdminResponse::success('', $operation);
    }
}
