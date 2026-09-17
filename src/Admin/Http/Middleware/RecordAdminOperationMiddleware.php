<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 记录后台写请求的最小审计信息；不保存请求正文，避免密码、Token 和插件密钥进入日志表。
 */
final class RecordAdminOperationMiddleware
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        $session = (array) $request->attributes->get('ptadmin.session', []);
        if (($session['guard_name'] ?? '') !== 'admin') {
            return $response;
        }
        $route = $request->route();
        $resourceKey = (string) ($request->route('resource') ?? '');
        if ($resourceKey === '') {
            $resourceKey = $route ? (string) $route->getName() : '';
        }
        $this->database->table('admin_operation_records')->insert([
            'admin_id' => (int) ($session['subject_id'] ?? 0),
            'request_id' => (string) $request->attributes->get('ptadmin.request_id', ''),
            'resource_key' => $resourceKey,
            'action' => $route ? (string) $route->getName() : '',
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'successful' => $response->getStatusCode() < 400,
            'ip_address' => (string) $request->getClientIp(),
            'created_at' => now(),
        ]);

        return $response;
    }
}
