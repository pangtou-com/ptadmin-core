<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Auth\Exceptions\InvalidCredentialsException;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class LoginController extends Controller
{
    public function __construct(private AdminAuthenticator $authenticator)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
            ], [
                'username.required' => '请输入用户名。',
                'username.string' => '用户名格式不正确。',
                'password.required' => '请输入密码。',
                'password.string' => '密码格式不正确。',
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(42200, '登录信息校验失败。', $exception->errors(), 422);
        }

        try {
            $session = $this->authenticator->login(
                (string) $validated['username'],
                (string) $validated['password'],
                (string) $request->getClientIp(),
                (string) $request->userAgent()
            );
        } catch (InvalidCredentialsException $exception) {
            return AdminResponse::error(40100, $exception->getMessage(), null, 401);
        }

        return AdminResponse::success('', $session);
    }
}
