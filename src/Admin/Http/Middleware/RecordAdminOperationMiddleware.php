<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/** 记录后台写请求的审计信息，并对请求参数作脱敏和限量处理。 */
final class RecordAdminOperationMiddleware
{
    private const MAX_PAYLOAD_BYTES = 16_384;
    private const MAX_STRING_BYTES = 1_024;
    private const MAX_ARRAY_ITEMS = 50;
    private const MAX_DEPTH = 5;

    public function __construct(private ConnectionInterface $database)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
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
        if ($resourceKey === '' && $route instanceof Route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'ptadmin.permission:')) {
                    $resourceKey = explode(',', substr($middleware, strlen('ptadmin.permission:')), 2)[0];
                    break;
                }
            }
        }
        if ($resourceKey === '') {
            $resourceKey = $route instanceof Route ? (string) $route->getName() : '';
        }
        $this->database->table('admin_operation_records')->insert([
            'admin_id' => (int) ($session['subject_id'] ?? 0),
            'request_id' => (string) $request->attributes->get('ptadmin.request_id', ''),
            'resource_key' => $resourceKey,
            'action' => $route instanceof Route ? (string) $route->getName() : '',
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'response_time_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'request_payload' => $this->requestPayload($request),
            'successful' => $response->getStatusCode() < 400,
            'ip_address' => (string) $request->getClientIp(),
            'created_at' => now(),
        ]);

        return $response;
    }

    private function requestPayload(Request $request): string
    {
        $payload = $this->sanitizeValue($request->input(), 0);
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (!is_string($encoded) || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return '{"_truncated":true,"_reason":"payload_too_large"}';
        }

        return $encoded;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => true, '_reason' => 'max_depth'];
        }
        if (is_array($value)) {
            $sanitized = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= self::MAX_ARRAY_ITEMS) {
                    $sanitized['_truncated'] = true;
                    $sanitized['_reason'] = 'max_items';
                    break;
                }

                $key = (string) $key;
                $sanitized[$key] = $this->isSensitiveKey($key)
                    ? '[masked]'
                    : $this->sanitizeValue($item, $depth + 1);
            }

            return $sanitized;
        }
        if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
            return substr($value, 0, self::MAX_STRING_BYTES).'[truncated]';
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[unsupported_value]';
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/password|token|secret|authorization|cookie|api[_-]?key|private[_-]?key|credential|content[_-]?base64/i',
            $key,
        ) === 1;
    }
}
