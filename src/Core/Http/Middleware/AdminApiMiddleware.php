<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 在路由匹配前设置请求标识，覆盖插件 API、404 和 405。 */
final class AdminApiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
        if (!$request->is($prefix, $prefix.'/*')) {
            return $next($request);
        }
        return (new RequestIdMiddleware())->handle($request, $next);
    }
}
