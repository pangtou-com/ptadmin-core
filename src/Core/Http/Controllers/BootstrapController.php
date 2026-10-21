<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class BootstrapController extends Controller
{
    public function __construct(
        private readonly ApplicationIdentity $identity,
    )
    {
    }

    public function __invoke(): JsonResponse
    {
        $apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
        $applicationId = $this->identity->load()['application_instance_id'];

        return AdminResponse::success('', [
            'app_id' => $applicationId,
            'runtime' => [
                'api_base_path' => '/'.$apiPrefix,
                'web_prefix' => (string) config('ptadmin.web_prefix', 'admin'),
                'frontend_version' => $this->frontendVersion(),
                'backend_version' => (string) config('ptadmin.backend_version', '0.1.0'),
                'api_protocol' => (int) config('ptadmin.api_protocol', 1),
                'schema_protocol' => (int) config('ptadmin.schema_protocol', 1),
            ],
            'brand' => ['name' => 'PTAdmin'],
            'auth' => [
                'login_path' => (string) config('ptadmin.auth.login_path', '/login'),
                'session_path' => (string) config('ptadmin.auth.session_path', '/session'),
                'renew_path' => (string) config('ptadmin.auth.renew_path', '/session/renew'),
            ],
            'theme' => (array) config('ptadmin.theme', []),
            'layout' => (array) config('ptadmin.layout', []),
        ]);
    }

    private function frontendVersion(): string
    {
        $webPrefix = (string) config('ptadmin.web_prefix', 'admin');
        $path = public_path($webPrefix.'/manifest.json');
        // 运行时只读取版本元信息；手动部署缺少清单或读取失败不阻断启动。
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            return 'unknown';
        }

        $manifest = json_decode($contents, true);
        $version = is_array($manifest) ? ($manifest['frontend_version'] ?? null) : null;

        return is_string($version) && trim($version) !== '' ? $version : 'unknown';
    }
}
