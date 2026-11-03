<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PTAdmin\Core\Install\InstallStateStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * 在路由解析、控制器构造和认证等业务中间件之前拦截未安装请求。
 * 安装状态只读取文件，安装入口可继续进行环境检查及正式数据库初始化。
 */
final class InstallStateMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $state = app(InstallStateStore::class);
        $installed = $state->isInstalled();
        $installPath = '__ptadmin__/install';
        $requestPath = trim($request->path(), '/');
        $isInstallRequest = $requestPath === $installPath || str_starts_with($requestPath, $installPath.'/');

        if ($installed && $isInstallRequest) {
            abort(404);
        }

        if (!$installed && !$isInstallRequest) {
            return redirect('/'.$installPath);
        }

        return $next($request);
    }
}
