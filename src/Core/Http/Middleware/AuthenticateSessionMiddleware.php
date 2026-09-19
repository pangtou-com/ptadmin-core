<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Core\Auth\Exceptions\InvalidSessionException;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSessionMiddleware
{
    public function __construct(
        private SessionManager $sessions,
        private SchemaSubjectContextInterface $schemaSubject
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $session = $this->sessions->resolve((string) $request->bearerToken());
        } catch (InvalidSessionException $exception) {
            return AdminResponse::error(40101, $exception->getMessage(), null, 401);
        }

        $request->attributes->set('ptadmin.session', $session);
        $admin = Admin::query()->find($session['subject_id'] ?? null);
        if ('admin' === ($session['guard_name'] ?? null)) {
            if (!$admin instanceof Admin || !(bool) $admin->status) {
                return AdminResponse::error(40102, '管理员账号不可用，请重新登录。', null, 401);
            }
            $this->schemaSubject->set($admin);
        }

        return $next($request);
    }
}
