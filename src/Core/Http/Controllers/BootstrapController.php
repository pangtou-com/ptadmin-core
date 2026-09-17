<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Exceptions\ApplicationIdentityUnavailableException;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Install\AdminFrontendManifest;

final class BootstrapController extends Controller
{
    public function __construct(
        private ApplicationIdentity $identity,
        private AdminFrontendManifest $frontendManifest,
    )
    {
    }

    public function __invoke(): JsonResponse
    {
        $apiPrefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
        $applicationId = null;
        try {
            $applicationId = $this->identity->load()['application_instance_id'];
        } catch (ApplicationIdentityUnavailableException) {
            // Bootstrap remains readable before installation; the installer creates the identity later.
        }

        return AdminResponse::success('', [
            'app_id' => $applicationId ?? '',
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
        $published = public_path($webPrefix);
        $directory = is_dir($published)
            ? $published
            : (string) config('ptadmin.frontend.bundled_path');
        $manifest = $this->frontendManifest->verify($directory);

        return (string) $manifest['frontend_version'];
    }
}
