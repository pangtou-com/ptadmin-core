<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class ProfileController extends Controller
{
    public function __construct(private AdminAuthenticator $authenticator, private SessionManager $sessions)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->authenticator->userPayload($this->admin($request)));
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'nickname' => ['required', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255'],
                'mobile' => ['nullable', 'string', 'max:30'],
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(42200, 'The profile payload is invalid.', $exception->errors(), 422);
        }

        $admin = $this->admin($request);
        $admin->forceFill($data)->save();

        return AdminResponse::success('', $this->authenticator->userPayload($admin));
    }

    public function password(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:6', 'max:4096', 'confirmed'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(42200, 'The password payload is invalid.', $exception->errors(), 422);
        }

        $admin = $this->admin($request);
        if (!Hash::check((string) $data['current_password'], (string) $admin->password)) {
            return AdminResponse::error(42201, 'The current password is incorrect.', ['field' => 'current_password'], 422);
        }
        $admin->forceFill(['password' => Hash::make((string) $data['password'])])->save();
        $revoked = $this->sessions->revokeSubject(Admin::class, (int) $admin->getKey(), 'admin');

        return AdminResponse::success('', ['revoked_sessions' => $revoked]);
    }

    private function admin(Request $request): Admin
    {
        $session = (array) $request->attributes->get('ptadmin.session', []);

        return Admin::query()->findOrFail($session['subject_id'] ?? null);
    }
}
