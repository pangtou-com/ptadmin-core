<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Http\Responses\AdminResponse;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermissionMiddleware
{
    public function __construct(private AuthorizationService $authorization)
    {
    }

    public function handle(Request $request, Closure $next, string $resourceKey, string $ability = 'view'): Response
    {
        $session = $request->attributes->get('ptadmin.session');
        $admin = is_array($session) ? Admin::query()->find($session['subject_id'] ?? null) : null;
        if (!$admin instanceof Admin || 'admin' !== ($session['guard_name'] ?? null)) {
            return AdminResponse::error(40102, 'The administrator account is unavailable.', null, 401);
        }

        if (!$this->authorization->allows($admin, $ability, $resourceKey)) {
            return AdminResponse::error(40300, 'You are not allowed to perform this action.', [
                'resource' => $resourceKey,
                'ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
