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

    public function store(Request $request, ConfigService $configs): JsonResponse
    {
        try {
            $payload = $request->all();
            return AdminResponse::success('', $configs->syncDefinition([
                'owner_type' => 'app',
                'owner_code' => 'host',
                'config_key' => $payload['code'] ?? $payload['config_key'] ?? '',
                'group_code' => $payload['group_code'] ?? 'system',
                'title' => $payload['title'] ?? '',
                'description' => $payload['description'] ?? '',
                'exposure' => $payload['exposure'] ?? 'internal',
                'activation_mode' => $payload['activation_mode'] ?? 'immediate',
                'schema' => $payload['schema'] ?? [],
                'status' => true,
            ]));
        } catch (ConfigException $exception) {
            return $this->error($exception);
        }
    }

    public function destroy(string $configKey, ConfigService $configs): JsonResponse
    {
        try {
            return AdminResponse::success('', $configs->deactivateDefinition('app', 'host', $configKey));
        } catch (ConfigException $exception) {
            return $this->error($exception);
        }
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

    public function addonDefinitions(string $code, ConfigService $configs): JsonResponse
    {
        return AdminResponse::success('', [
            'results' => $configs->definitions(['owner_type' => 'addon', 'owner_code' => $code]),
        ]);
    }

    public function addonShow(string $code, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        return $this->show('addon', $code, $configKey, $request, $configs);
    }

    public function addonUpdate(string $code, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        return $this->update('addon', $code, $configKey, $request, $configs);
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
                (string) ($payload['scope_id'] ?? ''),
                array_key_exists('expected_revision', $payload) ? (int) $payload['expected_revision'] : null
            ));
        } catch (ConfigException $exception) {
            return $this->error($exception);
        }
    }

    private function error(ConfigException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'config_not_found', 'config_scope_not_available' => 404,
            'config_revision_conflict' => 409,
            default => 422,
        };

        return AdminResponse::error(42200, $exception->getMessage(), [
            'error_code' => $exception->errorCode,
            'details' => $exception->details,
        ], $status);
    }
}
