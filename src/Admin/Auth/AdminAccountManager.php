<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;
use RuntimeException;

final class AdminAccountManager
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly SessionManager $sessions,
    ) {
    }

    public function create(string $username, string $password): Admin
    {
        $this->validateUsername($username);
        $this->validatePassword($password);

        if (Admin::withTrashed()->where('username', $username)->exists()) {
            throw new RuntimeException(sprintf('管理员用户名 %s 已存在或曾被删除，不能复用。', $username));
        }

        return Admin::query()->forceCreate([
            'username' => $username,
            'nickname' => $username,
            'password' => Hash::make($password),
            'status' => true,
            'is_founder' => false,
        ]);
    }

    public function resetPassword(string $username, string $password): Admin
    {
        $this->validatePassword($password);
        $admin = Admin::query()->where('username', $username)->first();
        if ($admin === null) {
            throw new RuntimeException(sprintf('找不到未删除的管理员账号 %s。', $username));
        }

        $this->resetPasswordFor($admin, $password);

        return $admin;
    }

    public function resetPasswordFor(Admin $admin, string $password): void
    {
        $this->validatePassword($password);
        $this->database->transaction(function () use ($admin, $password): void {
            $admin->forceFill(['password' => Hash::make($password)])->save();
            $this->sessions->revokeSubject(Admin::class, (int) $admin->getKey(), 'admin');
        });
    }

    public function delete(string $username): Admin
    {
        return $this->database->transaction(function () use ($username): Admin {
            $admin = Admin::query()->where('username', $username)->lockForUpdate()->first();
            if ($admin === null) {
                throw new RuntimeException(sprintf('找不到未删除的管理员账号 %s。', $username));
            }
            if ((bool) $admin->is_founder) {
                throw new RuntimeException('创始人管理员不能删除。');
            }

            $this->sessions->revokeSubject(Admin::class, (int) $admin->getKey(), 'admin');
            $admin->delete();
            $this->database->table('admin_user_roles')->where('admin_id', $admin->getKey())->delete();
            $this->database->table('admin_grants')
                ->where('subject_type', 'admin')
                ->where('subject_id', (string) $admin->getKey())
                ->delete();

            return $admin;
        });
    }

    private function validateUsername(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{4,36}$/D', $username) !== 1) {
            throw ValidationException::withMessages([
                'username' => '用户名只能包含字母、数字、点、下划线和短横线，长度为 4 到 36 个字符。',
            ]);
        }
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 6 || mb_strlen($password) > 36) {
            throw ValidationException::withMessages([
                'password' => '管理员密码长度必须为 6 到 36 个字符。',
            ]);
        }
    }
}
