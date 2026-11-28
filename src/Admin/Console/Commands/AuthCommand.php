<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use PTAdmin\Admin\Auth\AdminAccountManager;
use PTAdmin\Core\Install\InstallStateStore;
use RuntimeException;
use Throwable;

final class AuthCommand extends Command
{
    protected $signature = 'ptadmin:auth
        {action : reset、create 或 delete}
        {--u|username= : 管理员用户名}
        {--prompt-password : 通过隐藏终端输入自定义密码}
        {--password-stdin : 从标准输入读取自定义密码}
        {--yes : 确认删除管理员账号}';

    protected $description = '通过服务器命令行恢复或管理管理员账号';

    public function handle(AdminAccountManager $admins, InstallStateStore $installation): int
    {
        if (!$installation->isInstalled()) {
            $this->error('请先完成 PTAdmin 安装，再管理管理员账号。');
            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        if (!in_array($action, ['reset', 'create', 'delete'], true)) {
            $this->error('未知操作。可用操作：reset、create、delete。');
            return self::INVALID;
        }

        $username = $this->option('username');
        if (!is_string($username) || $username === '') {
            $this->error('请通过 --username 或 -u 指定管理员用户名。');
            return self::INVALID;
        }

        if ($action === 'delete') {
            return $this->delete($admins, $username);
        }

        if ($this->option('yes')) {
            $this->error('--yes 仅用于确认 delete 操作。');
            return self::INVALID;
        }

        try {
            $generatedPassword = !$this->option('prompt-password') && !$this->option('password-stdin');
            $password = $this->password();
            $admin = $action === 'create'
                ? $admins->create($username, $password)
                : $admins->resetPassword($username, $password);
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));
            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('管理员账号 %s 已%s。', $admin->username, $action === 'create' ? '创建' : '重置密码'));
        if ($action === 'create') {
            $this->line('新账号未分配角色或直接权限，请由已登录管理员完成授权。');
        }
        if ($generatedPassword) {
            $this->warn('请立即保存本次生成的密码；它只显示这一次。');
            $this->line('密码：'.$password);
        } else {
            $this->line('自定义密码已保存，未回显。');
        }

        return self::SUCCESS;
    }

    private function delete(AdminAccountManager $admins, string $username): int
    {
        if ($this->option('prompt-password') || $this->option('password-stdin')) {
            $this->error('delete 操作不接受密码选项。');
            return self::INVALID;
        }
        if (!$this->option('yes') && (!$this->input->isInteractive() || !$this->confirm(sprintf('确认软删除管理员 %s 并撤销其会话？', $username), false))) {
            $this->warn('已取消删除。');
            return self::FAILURE;
        }

        try {
            $admin = $admins->delete($username);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('管理员账号 %s 已软删除，关联授权和登录会话已清理。', $admin->username));
        return self::SUCCESS;
    }

    private function password(): string
    {
        if ($this->option('prompt-password') && $this->option('password-stdin')) {
            throw new RuntimeException('--prompt-password 与 --password-stdin 不能同时使用。');
        }
        if ($this->option('prompt-password')) {
            if (!$this->input->isInteractive()) {
                throw new RuntimeException('--prompt-password 需要交互式终端。');
            }
            $password = (string) $this->secret('请输入新密码（6–36 个字符）');
            $confirmation = (string) $this->secret('请再次输入新密码');
            if ($password !== $confirmation) {
                throw new RuntimeException('两次输入的密码不一致。');
            }
            return $password;
        }
        if ($this->option('password-stdin')) {
            $password = stream_get_contents(STDIN);
            if (!is_string($password)) {
                throw new RuntimeException('无法从标准输入读取密码。');
            }
            return rtrim($password, "\r\n");
        }

        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
