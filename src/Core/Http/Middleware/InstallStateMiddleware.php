<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Core\Install\InstallStateStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installation gate for the host Web middleware group. It is injected only
 * while the application is uninstalled; installation APIs remain outside the
 * Web group and stay reachable during bootstrap.
 */
final class InstallStateMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $state = app(InstallStateStore::class);
        $installPath = trim((string) config('ptadmin.install.path', '__ptadmin__/install'), '/');
        $requestPath = trim($request->path(), '/');
        $isInstallRequest = $requestPath === $installPath || str_starts_with($requestPath, $installPath.'/');

        if ($state->isInstalled() && $isInstallRequest) {
            abort(404);
        }

        if (!$state->isInstalled() && !$isInstallRequest) {
            return redirect('/'.$installPath);
        }

        return $next($request);
    }
}
