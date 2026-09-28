<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskManager;

final class AddonTaskController extends Controller
{
    public function download(Request $request, TaskManager $tasks): JsonResponse
    {
        $source = $request->input('source', $request->input('url'));
        if (!is_string($source) || trim($source) === '') {
            return AdminResponse::error(42200, 'Addon source is required.', ['error_code' => 'addon_source_required'], 422);
        }
        $payload = ['source' => trim($source)];
        if ($request->filled('filename')) {
            $payload['filename'] = (string) $request->input('filename');
        }
        if ($request->filled('sha256')) {
            $sha256 = (string) $request->input('sha256');
            if (preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
                return AdminResponse::error(42200, 'Addon SHA-256 is invalid.', ['error_code' => 'addon_checksum_invalid'], 422);
            }
            $payload['sha256'] = strtolower($sha256);
        }
        return $this->create($tasks, TaskManager::ADDON_DOWNLOAD, $payload, $request);
    }

    public function install(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->packageTask($code, $request, $tasks, TaskManager::ADDON_INSTALL);
    }

    public function update(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->packageTask($code, $request, $tasks, TaskManager::ADDON_UPDATE);
    }

    public function uninstall(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->create($tasks, TaskManager::ADDON_UNINSTALL, [
            'code' => $code,
            'web_prefix' => $this->webPrefix($request),
        ], $request);
    }

    public function frontendSync(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->create($tasks, TaskManager::ADDON_FRONTEND_SYNC, [
            'code' => $code,
            'web_prefix' => $this->webPrefix($request),
        ], $request);
    }

    public function enable(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->stateTask($code, $request, $tasks, TaskManager::ADDON_ENABLE);
    }

    public function disable(string $code, Request $request, TaskManager $tasks): JsonResponse
    {
        return $this->stateTask($code, $request, $tasks, TaskManager::ADDON_DISABLE);
    }

    private function stateTask(string $code, Request $request, TaskManager $tasks, string $type): JsonResponse
    {
        return $this->create($tasks, $type, ['code' => $code], $request);
    }

    private function packageTask(string $code, Request $request, TaskManager $tasks, string $type): JsonResponse
    {
        $source = $request->input('source', $request->input('url'));
        if (!is_string($source) || trim($source) === '') {
            return AdminResponse::error(42200, 'Addon source is required.', ['error_code' => 'addon_source_required'], 422);
        }
        $payload = [
            'code' => $code,
            'source' => trim($source),
            'web_prefix' => $this->webPrefix($request),
        ];
        if ($request->filled('sha256')) {
            $sha256 = (string) $request->input('sha256');
            if (preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
                return AdminResponse::error(42200, 'Addon SHA-256 is invalid.', ['error_code' => 'addon_checksum_invalid'], 422);
            }
            $payload['sha256'] = strtolower($sha256);
        }
        return $this->create($tasks, $type, $payload, $request);
    }

    /** @param array<string, mixed> $payload */
    private function create(TaskManager $tasks, string $type, array $payload, Request $request): JsonResponse
    {
        try {
            $result = $tasks->create(
                $type,
                $payload,
                $request->attributes->get('ptadmin.request_id'),
                max(1, min(20, (int) $request->input('max_attempts', 1))),
                $request->header('Idempotency-Key')
            );
            return AdminResponse::success('', $result, 202);
        } catch (TaskException $exception) {
            $status = $exception->errorCode === 'task_in_progress' ? 409 : 422;
            return AdminResponse::error($status === 409 ? 40900 : 42200, $exception->getMessage(), ['error_code' => $exception->errorCode, 'details' => $exception->details], $status);
        }
    }

    private function webPrefix(Request $request): string
    {
        return trim((string) $request->input('web_prefix', config('ptadmin.web_prefix', 'admin')));
    }
}
