<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Install\InstallStateStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use RuntimeException;

/**
 * 返回已发布后台的 HTML 入口，并为前端 history 路由提供回退。
 * 静态资源仍由 Web Server 直接处理，控制器只响应需要校验运行时前缀的入口请求。
 */
final class AdminFrontendController extends Controller
{
    public function __construct(private InstallStateStore $state)
    {
    }

    public function index(Request $request): BinaryFileResponse
    {
        return response()->file($this->entryPath($request), [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function entryPath(Request $request): string
    {
        if (!$this->state->isInstalled()) {
            abort(404);
        }

        $installed = $this->state->readInstalled();
        $prefix = is_array($installed) ? (string) ($installed['web_prefix'] ?? '') : '';
        if ($prefix === '') {
            throw new RuntimeException('The PTAdmin installed marker has no web prefix.');
        }

        $requestPath = trim($request->path(), '/');
        if ($requestPath !== $prefix && !str_starts_with($requestPath, $prefix.'/')) {
            abort(404);
        }

        $path = public_path($prefix.'/index.html');
        if (!is_file($path)) {
            abort(404);
        }

        return $path;
    }
}
