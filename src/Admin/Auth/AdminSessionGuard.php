<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PTAdmin\Admin\Auth\Exceptions\AdminAuthenticationException;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Exceptions\InvalidSessionException;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Auth\Session\SessionRevoked;
use PTAdmin\Core\Install\InstallStateStore;

/** Bearer 会话认证结果仅在当前请求复用，包含未登录和无效会话。 */
final class AdminSessionGuard implements Guard
{
    private bool $resolved = false;
    private ?Admin $admin = null;
    /** @var array<string,mixed>|null */
    private ?array $session = null;
    private ?AdminAuthenticationException $failure = null;

    /** @param Closure():SessionManager $sessions 安装检查之后才解析数据库依赖。 */
    public function __construct(
        private Request $request,
        private readonly InstallStateStore $installation,
        private readonly Closure $sessions,
    ) {
    }

    public function user(): ?Admin
    {
        return $this->currentAdmin();
    }

    /** 提供强类型后台身份，避免 Laravel 通用 Guard 的用户类型推断混入前台模型。 */
    public function currentAdmin(): ?Admin
    {
        if ($this->resolved) {
            return $this->admin;
        }
        $this->request->attributes->set(self::class, $this);
        if (!$this->installation->isInstalled()) {
            $this->reject('后台尚未安装。');
            return null;
        }
        $token = (string) $this->request->bearerToken();
        if ($token === '') {
            $this->reject('登录会话不存在，请重新登录。');
            return null;
        }
        try {
            $session = ($this->sessions)()->resolve($token);
        } catch (InvalidSessionException $exception) {
            $this->reject($exception->getMessage());
            return null;
        }
        if ($session['guard_name'] !== 'admin' || $session['subject_type'] !== Admin::class) {
            $this->reject('管理员会话身份不匹配，请重新登录。', 40102);
            return null;
        }
        $admin = Admin::query()->find($session['subject_id']);
        if (!$admin instanceof Admin || !$admin->status) {
            $this->reject('管理员账号不可用，请重新登录。', 40102);
            return null;
        }
        $this->session = $session;
        $this->admin = $admin;
        $this->resolved = true;

        return $admin;
    }

    public function authenticate(): Admin
    {
        return $this->currentAdmin() ?? throw ($this->failure ?? new AdminAuthenticationException('请先登录。'));
    }

    /** @return array<string,mixed> */
    public function session(): array
    {
        $this->authenticate();
        return $this->session ?? throw new AdminAuthenticationException('登录会话不存在，请重新登录。');
    }

    public function check(): bool { return $this->user() !== null; }
    public function guest(): bool { return !$this->check(); }
    public function id(): ?int { return $this->user()?->id; }
    public function hasUser(): bool { return $this->admin !== null; }

    /** 此 Guard 只验证当前请求的 Bearer Token；密码登录由 AdminAuthenticator 提供。
     * @param array<string,mixed> $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return $credentials === [] && $this->check();
    }

    public function setUser(Authenticatable $user): static
    {
        if (!$user instanceof Admin || !$user->status || $user->trashed()) {
            throw new InvalidArgumentException('ptadmin Guard 只接受有效的 Admin 身份。');
        }
        $this->admin = $user;
        $this->session = null;
        $this->failure = null;
        $this->resolved = true;
        return $this;
    }

    public function setRequest(Request $request): static
    {
        if ($this->request !== $request) {
            $this->request->attributes->remove(self::class);
            $this->request = $request;
            $this->resolved = false;
            $this->admin = null;
            $this->session = null;
            $this->failure = null;
        }
        return $this;
    }

    /** 请求完成后不允许从仍绑定的旧 Request 再次恢复身份。 */
    public function clear(): void
    {
        $this->reject('当前请求的认证上下文已结束。');
        $this->request->attributes->remove(self::class);
    }

    public function invalidate(SessionRevoked $event): bool
    {
        if ($this->session === null) {
            return false;
        }
        $matches = $event->tokenHash !== null
            ? hash_equals($event->tokenHash, hash('sha256', (string) $this->request->bearerToken()))
            : ($event->subjectType === $this->session['subject_type']
                && $event->subjectId === $this->session['subject_id']
                && $event->guardName === $this->session['guard_name']
                && ($event->sessionId === null || $event->sessionId === $this->session['id']));
        if ($matches) {
            $this->reject('登录会话已撤销，请重新登录。');
        }
        return $matches;
    }

    private function reject(string $message, int $code = 40101): void
    {
        $this->admin = null;
        $this->session = null;
        $this->resolved = true;
        $this->failure = new AdminAuthenticationException($message, $code);
    }
}
