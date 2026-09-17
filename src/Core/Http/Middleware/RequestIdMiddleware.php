<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request->header('X-Request-ID'));
        $request->attributes->set('ptadmin.request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function resolveRequestId(?string $candidate): string
    {
        if (is_string($candidate)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $candidate) === 1) {
            return $candidate;
        }

        return bin2hex(random_bytes(16));
    }
}
