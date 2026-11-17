<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Admin\Auth\AdminAuth;
use PTAdmin\Admin\Auth\Exceptions\AdminAuthenticationException;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSessionMiddleware
{
    public function __construct(
        private AdminAuth $auth,
        private SchemaSubjectContextInterface $schemaSubject
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->schemaSubject->set(null);
        $guard = $this->auth->guard()->setRequest($request);
        try {
            $admin = $guard->authenticate();
            $request->attributes->set('ptadmin.session', $guard->session());
            $this->schemaSubject->set($admin);
            return $next($request);
        } catch (AdminAuthenticationException $exception) {
            return AdminResponse::error($exception->errorCode, $exception->getMessage(), null, 401);
        } finally {
            // 同进程后续任务不能继承当前 HTTP 管理员身份。
            $this->schemaSubject->set(null);
            $guard->clear();
        }
    }
}
