<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Admin\Auth\AdminAuth;
use PTAdmin\Core\Http\Responses\AdminResponse;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermissionMiddleware
{
    public function __construct(private AdminAuth $auth)
    {
    }

    public function handle(Request $request, Closure $next, string $resourceKey, string $ability = 'view'): Response
    {
        $this->auth->authenticate();

        if (!$this->auth->can($resourceKey.'.'.$ability)) {
            return AdminResponse::error(40300, '当前账号无权执行此操作。', [
                'resource' => $resourceKey,
                'ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
