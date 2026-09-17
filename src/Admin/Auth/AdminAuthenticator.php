<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use PTAdmin\Admin\Auth\Exceptions\InvalidCredentialsException;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;

final class AdminAuthenticator
{
    public function __construct(private SessionManager $sessions, private ConnectionInterface $database)
    {
    }

    /**
     * @return array{access_token:string, session_id:string, expires_at:string, absolute_expires_at:string, user:array<string,mixed>}
     */
    public function login(string $username, string $password, string $ip = '', string $userAgent = ''): array
    {
        $username = trim($username);
        /** @var Admin|null $admin */
        $admin = Admin::query()->where('username', $username)->first();
        if (null === $admin || !Hash::check($password, (string) $admin->password) || 1 !== (int) $admin->status) {
            $this->recordLogin($admin, $username, false, 'invalid_credentials', $ip, $userAgent);
            throw new InvalidCredentialsException('Invalid administrator credentials.');
        }

        $admin->forceFill([
            'login_at' => now(),
            'login_ip' => '' !== $ip ? $ip : null,
        ])->save();

        $session = $this->sessions->issue(
            Admin::class,
            (int) $admin->getKey(),
            'admin',
            ['user_agent' => $userAgent]
        );
        $this->recordLogin($admin, $username, true, '', $ip, $userAgent);

        return $session->toArray() + [
            'user' => $this->userPayload($admin),
        ];
    }

    private function recordLogin(?Admin $admin, string $username, bool $successful, string $failureReason, string $ip, string $userAgent): void
    {
        $this->database->table('admin_login_logs')->insert([
            'admin_id' => $admin?->getKey(),
            'username' => $username,
            'successful' => $successful,
            'failure_reason' => $failureReason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function userPayload(Admin $admin): array
    {
        return [
            'id' => (int) $admin->getKey(),
            'username' => (string) $admin->username,
            'nickname' => (string) $admin->nickname,
            'email' => $admin->email,
            'mobile' => $admin->mobile,
            'avatar' => $admin->avatar,
            'is_founder' => (bool) $admin->is_founder,
        ];
    }
}
