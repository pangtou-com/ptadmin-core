<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Schema\Contracts\RuntimeEngineInterface;
use PTAdmin\Schema\Http\RuntimeOperationDispatcher;

/**
 * 标准资源 CRUD 统一入口；具体资源定义、字段校验和授权由 Schema Runtime 处理。
 */
final class RuntimeController extends Controller
{
    public function dispatch(Request $request, RuntimeOperationDispatcher $dispatcher): JsonResponse
    {
        return $this->respond(fn (): mixed => $dispatcher->dispatch(
            (string) $request->input('operation', ''),
            (array) $request->input('context', []),
        ));
    }

    public function list(string $resource, Request $request, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->list(array_merge($this->query($request), ['resource' => $resource])));
    }

    public function detail(string $resource, int $id, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->detail(['resource' => $resource, 'id' => $id]));
    }

    public function create(string $resource, Request $request, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->create(array_merge($request->all(), ['resource' => $resource])));
    }

    public function update(string $resource, int $id, Request $request, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->update(array_merge($request->all(), ['resource' => $resource, 'id' => $id])));
    }

    public function delete(string $resource, int $id, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->delete(['resource' => $resource, 'id' => $id]));
    }

    public function restore(string $resource, int $id, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->restore(['resource' => $resource, 'id' => $id]));
    }

    public function purge(string $resource, int $id, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->purge(['resource' => $resource, 'id' => $id]));
    }

    public function action(string $resource, int $id, Request $request, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->executeAction(array_merge($request->all(), [
            'resource' => $resource,
            'id' => $id,
        ])));
    }

    public function relationCandidates(string $resource, Request $request, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->relationCandidates(array_merge($this->query($request), ['resource' => $resource])));
    }

    public function schema(string $resource, RuntimeEngineInterface $runtime): JsonResponse
    {
        return $this->respond(fn (): array => $runtime->schemaSnapshot(['resource' => $resource]));
    }

    /** @return array<string, mixed> */
    private function query(Request $request): array
    {
        $query = $request->query();
        foreach (['page', 'limit'] as $field) {
            if ($request->query->has($field)) {
                $query[$field] = $request->integer($field);
            }
        }

        return $query;
    }
}
