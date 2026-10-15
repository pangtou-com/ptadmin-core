<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskManager;

final class TaskController extends Controller
{
    private AuthorizationService $authorization;

    public function __construct(AuthorizationService $authorization)
    {
        $this->authorization = $authorization;
    }

    public function create(Request $request, TaskManager $tasks): JsonResponse
    {
        $payload = $request->all();
        $type = trim((string) ($payload['type'] ?? ''));
        try {
            // 模块任务必须经过模块自己的载荷校验和提交接口，通用入口只创建内置类型。
            if ($type === TaskManager::CLOUD_PACKAGE) {
                throw new TaskException('云安装任务必须通过云市场交付接口创建。', 'forbidden');
            }
            TaskManager::permissionFor($type);
            $this->assertTaskPermission($request, $type, 'create');
            $data = $payload['payload'] ?? [];
            if (!is_array($data)) {
                return AdminResponse::error(42200, 'Task payload must be an object.', null, 422);
            }
            $idempotencyKey = $payload['idempotency_key'] ?? $request->header('Idempotency-Key');
            $result = $tasks->create($type, $data, $this->requestId($request), (int) ($payload['max_attempts'] ?? 1), is_string($idempotencyKey) ? $idempotencyKey : null);
            return AdminResponse::success('', $result, 201);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function list(Request $request, TaskManager $tasks): JsonResponse
    {
        return AdminResponse::success('', $tasks->list((int) $request->query('limit', 50)));
    }

    public function show(string $taskId, Request $request, TaskManager $tasks): JsonResponse
    {
        try {
            $task = $tasks->get($taskId);
            $this->assertTaskPermissionForTask($task, $request, 'view');
            return AdminResponse::success('', $task);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function events(string $taskId, Request $request, TaskManager $tasks): JsonResponse
    {
        try {
            $task = $tasks->get($taskId);
            $this->assertTaskPermissionForTask($task, $request, 'view');
            return AdminResponse::success('', $tasks->events($taskId, (int) $request->query('cursor', 0), (int) $request->query('limit', 100)));
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function retry(string $taskId, Request $request, TaskManager $tasks): JsonResponse
    {
        try {
            $task = $tasks->get($taskId);
            $this->assertTaskPermission($request, (string) ($task['type'] ?? ''), 'update');
            $result = $tasks->retry($taskId, $this->requestId($request));
            if ($task['type'] === TaskManager::CLOUD_PACKAGE) {
                \Illuminate\Support\Facades\Bus::dispatchAfterResponse(new \PTAdmin\Cloud\ExecuteCloudPackageTask($taskId));
            }
            return AdminResponse::success('', $result);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function cancel(string $taskId, Request $request, TaskManager $tasks): JsonResponse
    {
        try {
            $task = $tasks->get($taskId);
            $this->assertTaskPermission($request, (string) ($task['type'] ?? ''), 'update');
            return AdminResponse::success('', $tasks->cancel($taskId, 'Task cancelled by administrator.', $this->requestId($request)));
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    private function assertTaskPermission(Request $request, string $type, string $ability): void
    {
        if ($type === TaskManager::CLOUD_PACKAGE) {
            try {
                app(\PTAdmin\Cloud\FounderAccess::class)->assert($request);
            } catch (\PTAdmin\Cloud\CloudException $exception) {
                throw new TaskException($exception->getMessage(), 'forbidden');
            }
        }
        $session = $request->attributes->get('ptadmin.session');
        $admin = is_array($session) ? Admin::query()->find($session['subject_id'] ?? null) : null;
        if (!$admin instanceof Admin) {
            throw new TaskException('The administrator account is unavailable.', 'unauthorized');
        }
        $resource = app(TaskManager::class)->resourceFor($type);
        if (!$this->authorization->allows($admin, $ability, $resource)) {
            throw new TaskException('You are not allowed to manage this task.', 'forbidden', ['resource' => $resource, 'ability' => $ability]);
        }
    }

    /** @param array<string, mixed> $task */
    private function assertTaskPermissionForTask(array $task, Request $request, string $ability): void
    {
        $this->assertTaskPermission($request, (string) ($task['type'] ?? ''), $ability);
    }

    private function requestId(Request $request): ?string
    {
        $value = $request->attributes->get('ptadmin.request_id');
        return is_string($value) ? $value : null;
    }

    private function error(TaskException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'task_in_progress' => 409,
            'task_not_found' => 404,
            'unauthorized' => 401,
            'forbidden' => 403,
            default => 422,
        };
        $code = match ($status) {
            401 => 40100,
            403 => 40300,
            404 => 40400,
            409 => 40900,
            default => 42200,
        };

        return AdminResponse::error($code, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], $status);
    }
}
