<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Core\Install\InstallStateStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * 宿主 Web 路由的安装门禁，使用保留安装地址和 JSON 安装标记延续原有跳转行为。
 */
final class InstallStateMiddleware
{
    public function __construct(private InstallStateStore $state)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $installPath = trim((string) config('ptadmin.install.path', '__ptadmin__/install'), '/');
        $requestPath = trim($request->path(), '/');
        $isInstallRequest = $requestPath === $installPath || str_starts_with($requestPath, $installPath.'/');

        if ($this->state->isInstalled() && $isInstallRequest) {
            abort(404);
        }

        if (!$this->state->isInstalled() && !$isInstallRequest) {
            return redirect('/'.$installPath);
        }

        return $next($request);
    }
}
