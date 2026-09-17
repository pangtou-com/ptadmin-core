<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Config\ConfigException;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Http\Responses\AdminResponse;

/**
 * 配置定义和值接口。定义同步由核心和插件服务调用，后台只读取和保存配置对象值。
 */
final class ConfigController extends Controller
{
    public function definitions(Request $request, ConfigService $configs): JsonResponse
    {
        return AdminResponse::success('', [
            'results' => $configs->definitions($request->only(['owner_type', 'owner_code', 'group_code', 'config_key'])),
            'groups' => \PTAdmin\Core\Config\ConfigGroups::all(),
        ]);
    }

    public function show(string $ownerType, string $ownerCode, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        try {
            return AdminResponse::success('', $configs->get(
                $ownerType,
                $ownerCode,
                $configKey,
                (string) $request->query('scope_type', 'global'),
                (string) $request->query('scope_id', '')
            ));
        } catch (ConfigException $exception) {
            return $this->error($exception);
        }
    }

    public function update(string $ownerType, string $ownerCode, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        $payload = $request->all();
        $data = $payload['data'] ?? $payload;
        if (!is_array($data)) {
            return AdminResponse::error(42200, 'Configuration data must be an object.', null, 422);
        }

        try {
            return AdminResponse::success('', $configs->put(
                $ownerType,
                $ownerCode,
                $configKey,
                $data,
                (string) ($payload['scope_type'] ?? 'global'),
                (string) ($payload['scope_id'] ?? '')
            ));
        } catch (ConfigException $exception) {
            return $this->error($exception);
        }
    }

    private function error(ConfigException $exception): JsonResponse
    {
        $status = in_array($exception->errorCode, ['config_not_found', 'config_scope_not_available'], true) ? 404 : 422;

        return AdminResponse::error(42200, $exception->getMessage(), [
            'error_code' => $exception->errorCode,
            'details' => $exception->details,
        ], $status);
    }
}
