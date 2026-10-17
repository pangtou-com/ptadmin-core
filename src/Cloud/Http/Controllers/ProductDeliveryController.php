<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\CloudPackageExecutor;
use PTAdmin\Cloud\CloudSessionManager;
use PTAdmin\Cloud\Contracts\ProductDeliveryClientInterface;
use PTAdmin\Cloud\ExecuteCloudPackageTask;
use PTAdmin\Cloud\FounderAccess;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskStore;
use Symfony\Component\HttpFoundation\Response;

final class ProductDeliveryController
{
    public function __construct(private readonly ProductDeliveryClientInterface $delivery,
        private readonly \PTAdmin\Cloud\LicenseCodeDeliveryClient $codes, private readonly CloudSessionManager $sessions, private readonly FounderAccess $founders, private readonly TaskManager $tasks)
    {
    }

    public function __invoke(Request $request): Response
    {
        $operation = $request->route('operation');
        $taskId = $request->route('taskId');
        try {
            $this->founders->assert($request);
            if ($operation === 'artifact') {
                $task = $this->tasks->get($taskId);
                if ($task['type'] !== CloudPackageExecutor::TYPE || $task['status'] !== 'succeeded' || !($task['result']['data']['download_ready'] ?? false)) {
                    throw new CloudException('下载文件尚未就绪。', 'invalid_request');
                }
                return response()->download(app(TaskStore::class)->artifactsPath($taskId).'/package.zip',
                    $task['payload']['code'].'-'.$task['payload']['version'].'-'.$task['payload']['package_kind'].'.zip',
                    ['Cache-Control' => 'private, no-store']);
            }
            $token = in_array($operation, ['code-options', 'package'], true) ? null : $this->sessions->token();
            $result = match ($operation) {
                'code-options' => $this->codeOptions($request),
                'redeem' => $this->delivery->redeemLicenseCode($token, $request->validate([
                    'license_code' => ['required', 'string', 'max:100'], 'expected_user_id' => ['required', 'integer', 'min:1'],
                ])),
                'options' => $this->options($request, $token),
                'project' => $this->delivery->project($token, $request->all()),
                'bind' => $this->delivery->bindLicense($token, $request->all()),
                'release' => $this->delivery->releaseLicense($token, $request->all()),
                'package' => $this->package($request),
                default => throw new CloudException('未知交付操作。', 'invalid_request'),
            };
            $response = AdminResponse::success('', $result, $operation === 'package' ? 202 : 200);
        } catch (CloudException $exception) {
            $status = match ($exception->errorCode) { 'founder_required', 'forbidden' => 403, 'unauthorized' => 401, 'too_many_requests' => 429, 'cloud_platform_unavailable' => 503, default => 422 };
            $response = AdminResponse::error($status * 100, $exception->getMessage(), ['error_code' => $exception->errorCode], $status);
        } catch (TaskException $exception) {
            $response = AdminResponse::error(40900, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], 409);
        } catch (ValidationException $exception) {
            $response = AdminResponse::error(42200, $exception->validator->errors()->first(), null, 422);
        }
        return $response->header('Cache-Control', 'no-store');
    }

    private function options(Request $request, string $token): array
    {
        $values = $request->validate(['code' => ['required', 'string', 'max:100'], 'product_type' => ['required', 'in:addon,template']]);
        $options = $this->delivery->deliveryOptions($token, $values);
        return $this->describeOptions($options);
    }

    private function codeOptions(Request $request): array
    {
        $values = $request->validate(['license_code' => ['required', 'string', 'max:100']]);
        return $this->describeOptions($this->codes->authorize($values['license_code']));
    }

    private function describeOptions(array $options): array
    {
        $policy = app(\PTAdmin\Cloud\CloudInstallationPolicy::class);
        foreach ($options['licenses'] as &$license) {
            foreach ($license['releases'] as &$release) {
                $release += $policy->describe($options['product']['code'], $options['product']['product_type'],
                    $release['version'], $release['core_version_range']);
            }
            unset($release);
        }
        unset($license);
        return $options;
    }

    private function package(Request $request): array
    {
        // 会员交付先检查云会话，再校验选包参数；只有独立授权码交付不依赖会员会话。
        $codeCredentialRequested = $request->input('code_credential_id') !== null;
        $memberToken = $codeCredentialRequested ? null : $this->sessions->token();
        $values = $request->validate(['license_id' => ['required', 'integer', 'min:1'], 'release_id' => ['required', 'integer', 'min:1'],
            'code_credential_id' => ['sometimes', 'integer', 'min:1'],
            'package_kind' => ['required', 'in:install,source'], 'operation' => ['required', 'in:install,update,download']]);
        if ($values['operation'] !== 'download' && $values['package_kind'] !== 'install') {
            throw new CloudException('源码包只能下载用于二次开发。', 'invalid_request');
        }
        $preview = isset($values['code_credential_id'])
            ? $this->codes->request((int) $values['code_credential_id'], 'prepare', $values)
            : $this->delivery->prepareDelivery((string) $memberToken, $values);
        $result = $this->tasks->create(CloudPackageExecutor::TYPE, $values + $preview,
            $request->attributes->get('ptadmin.request_id'), 3, $request->header('Idempotency-Key'));
        foreach ($values + $preview as $key => $value) {
            if (($result['payload'][$key] ?? null) !== $value) {
                throw new CloudException('该请求标识已用于其他版本任务，请重新确认。', 'invalid_request');
            }
        }
        if ($result['status'] === 'pending') {
            Bus::dispatchAfterResponse(new ExecuteCloudPackageTask($result['task_id']));
        }
        return $result;
    }
}
