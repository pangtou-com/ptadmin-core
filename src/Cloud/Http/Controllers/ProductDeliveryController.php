<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\CloudPackageExecutor;
use PTAdmin\Cloud\CloudSessionManager;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
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
    public function __construct(private readonly CloudClientInterface $client, private readonly ProductDeliveryClientInterface $delivery,
        private readonly CloudSessionManager $sessions, private readonly FounderAccess $founders, private readonly TaskManager $tasks)
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
            $token = $this->sessions->token();
            $result = match ($operation) {
                'project' => $this->delivery->project($token, $request->all()),
                'bind' => $this->delivery->bindLicense($token, $request->all()),
                'release' => $this->delivery->releaseLicense($token, $request->all()),
                'package' => $this->package($request, $token),
                default => throw new CloudException('未知交付操作。', 'invalid_request'),
            };
            $response = AdminResponse::success('', $result, $operation === 'package' ? 202 : 200);
        } catch (CloudException $exception) {
            $status = match ($exception->errorCode) { 'founder_required', 'forbidden' => 403, 'unauthorized' => 401, 'cloud_platform_unavailable' => 503, default => 422 };
            $response = AdminResponse::error($status * 100, $exception->getMessage(), ['error_code' => $exception->errorCode], $status);
        } catch (TaskException $exception) {
            $response = AdminResponse::error(40900, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], 409);
        } catch (ValidationException $exception) {
            $response = AdminResponse::error(42200, $exception->validator->errors()->first(), null, 422);
        }
        return $response->header('Cache-Control', 'no-store');
    }

    private function package(Request $request, string $token): array
    {
        $values = $request->validate(['license_id' => ['required', 'integer', 'min:1'], 'release_id' => ['required', 'integer', 'min:1'],
            'package_kind' => ['required', 'in:install,source'], 'operation' => ['required', 'in:install,update,download']]);
        if ($values['operation'] !== 'download' && $values['package_kind'] !== 'install') {
            throw new CloudException('源码包只能下载用于二次开发。', 'invalid_request');
        }
        $preview = $this->client->previewLicense($token, $values);
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
