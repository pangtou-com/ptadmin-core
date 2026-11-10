<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Admin\Auth\FounderAccess;
use PTAdmin\Core\Http\Responses\AdminResponse;
use Symfony\Component\HttpFoundation\Response;

final class RequireFounderMiddleware
{
    public function __construct(private readonly FounderAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->resolve($request) === null) {
            return AdminResponse::error(40300, '此功能仅允许启用的创始人账号操作。', null, 403);
        }
        return $next($request);
    }
}
