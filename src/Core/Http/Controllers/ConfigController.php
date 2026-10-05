<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use PTAdmin\Core\Config\ConfigException;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Config\MailConfigurationTester;
use PTAdmin\Core\Http\Responses\AdminResponse;

/**
 * 配置定义和值接口。定义同步由核心和插件服务调用，后台只读取和保存配置对象值。
 */
final class ConfigController extends Controller
{
    public function definitions(Request $request, ConfigService $configs): JsonResponse
    {
        return AdminResponse::success('', [
            'results' => $configs->definitions($request->only(['owner_type', 'owner_code', 'group_code', 'config_key']), false),
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
                'icon' => $payload['icon'] ?? 'general',
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
            $configs->assertGenericAccess($ownerType, $ownerCode, $configKey);
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
            'results' => $configs->definitions(['owner_type' => 'addon', 'owner_code' => strtolower($code)], false),
        ]);
    }

    public function addonShow(string $code, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        return $this->show('addon', strtolower($code), $configKey, $request, $configs);
    }

    public function addonUpdate(string $code, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        return $this->update('addon', strtolower($code), $configKey, $request, $configs);
    }

    public function update(string $ownerType, string $ownerCode, string $configKey, Request $request, ConfigService $configs): JsonResponse
    {
        $payload = $request->all();
        $data = $payload['data'] ?? $payload;
        if (!is_array($data)) {
            return AdminResponse::error(42200, 'Configuration data must be an object.', null, 422);
        }

        try {
            $configs->assertGenericAccess($ownerType, $ownerCode, $configKey);
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

    public function testMail(Request $request, MailConfigurationTester $tester): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email', 'max:254'],
            'values' => ['required', 'array:host,port,encryption,username,password,from_address,from_name'],
            'values.host' => ['required', 'string', 'max:255'],
            'values.port' => ['required', 'integer', 'between:1,65535'],
            'values.encryption' => ['present', 'nullable', 'string', 'in:,tls,ssl'],
            'values.username' => ['nullable', 'string', 'max:255'],
            'values.password' => ['nullable', 'string', 'max:4096'],
            'values.from_address' => ['required', 'email', 'max:254'],
            'values.from_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $tester->send($validated['recipient'], $validated['values']);
        } catch (\InvalidArgumentException) {
            return AdminResponse::error(42200, '邮件配置字段无效。', null, 422);
        } catch (\Throwable $exception) {
            Log::warning('PTAdmin 测试邮件发送失败', ['exception_type' => $exception::class]);

            return AdminResponse::error(50200, '测试邮件发送失败，请检查 SMTP 配置和服务器连接。', null, 502);
        }

        return AdminResponse::success('测试邮件已提交至邮件服务器。');
    }

    private function error(ConfigException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'config_not_found', 'config_scope_not_available' => 404,
            'config_revision_conflict' => 409,
            'config_dedicated_editor_required' => 403,
            default => 422,
        };

        return AdminResponse::error($status === 403 ? 40300 : 42200, $exception->getMessage(), [
            'error_code' => $exception->errorCode,
            'details' => $exception->details,
        ], $status);
    }
}
