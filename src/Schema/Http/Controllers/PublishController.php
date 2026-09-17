<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Schema\Contracts\PublishServiceInterface;

/**
 * 草稿检查、审批、发布和发布任务查询 API。
 */
final class PublishController extends Controller
{
    public function check(string $resource, Request $request, PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->checkDraft(array_merge($request->all(), ['resource' => $resource])));
    }

    public function approval(string $resource, PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->getDraftApproval($resource));
    }

    public function approve(string $resource, Request $request, PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->approveDraft(array_merge($request->all(), ['resource' => $resource])));
    }

    public function publish(string $resource, Request $request, PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->publishDraft(array_merge($request->all(), ['resource' => $resource])));
    }

    public function task(int $task, PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->getPublishTask($task));
    }
}
