<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class AddonController extends Controller
{
    public function index(AddonCatalog $discovery): JsonResponse
    {
        $result = $discovery->scan();
        $result['addons'] = array_map(static function (array $addon): array {
            $manifest = is_array($addon['manifest'] ?? null) ? $addon['manifest'] : [];
            return [
                'code' => (string) ($addon['code'] ?? ''),
                'version' => (string) ($addon['version'] ?? ''),
                'state' => (string) ($addon['state'] ?? ''),
                'disabled_by_user' => (bool) ($addon['disabled_by_user'] ?? false),
                'diagnostic_code' => $addon['diagnostic_code'] ?? null,
                'publisher' => (string) ($manifest['publisher'] ?? ''),
                'sandbox' => (string) ($manifest['sandbox'] ?? 'iframe'),
                'compatibility' => is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [],
                'frontend' => is_dir(rtrim((string) config('ptadmin.addon.path', base_path('addons')), '/').'/'.($addon['code'] ?? '').'/frontend/dist'),
            ];
        }, $result['addons']);
        return AdminResponse::success('', $result);
    }
}
