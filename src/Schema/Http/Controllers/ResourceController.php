<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;

/**
 * 资源、草稿、版本和资源状态管理 API。
 */
final class ResourceController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        return $this->respond(static function () use ($request): array {
            $query = Resource::query()
                ->where('status', 'active')
                ->whereNotNull('active_version_id')
                ->orderBy('title')
                ->orderBy('resource_key');
            foreach (['source_type', 'owner_type', 'owner_code', 'namespace'] as $field) {
                if ($request->filled($field)) {
                    $query->where($field, $request->query($field));
                }
            }
            if ($request->filled('keyword')) {
                $keyword = '%'.$request->query('keyword').'%';
                $query->where(static function ($nested) use ($keyword): void {
                    $nested->where('resource_key', 'like', $keyword)->orWhere('title', 'like', $keyword);
                });
            }
            $limit = min(100, max(1, $request->integer('limit', 100)));

            return ['results' => $query->limit($limit)->get()->map(static fn (Resource $resource): array => [
                'value' => $resource->resource_key,
                'label' => $resource->title !== '' ? $resource->title : $resource->resource_key,
                'resource_key' => $resource->resource_key,
                'description' => (string) $resource->description,
                'source_type' => (string) $resource->source_type,
                'owner_type' => (string) $resource->owner_type,
                'owner_code' => (string) $resource->owner_code,
                'version' => (int) $resource->active_version_id,
            ])->all()];
        });
    }

    public function index(Request $request, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(static function () use ($request, $resources): mixed {
            $query = $request->only(['keyword', 'namespace', 'source_type', 'owner_type', 'owner_code', 'status']);
            foreach (['page', 'limit'] as $field) {
                if ($request->query->has($field)) {
                    $query[$field] = $request->integer($field);
                }
            }

            return $resources->listResources($query);
        });
    }

    public function store(Request $request, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->createResource($request->all()));
    }

    public function show(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->getResource($resource));
    }

    public function draft(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->getDraftSchema($resource));
    }

    public function beginDraftEdit(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->beginDraftEdit($resource));
    }

    public function saveDraft(string $resource, Request $request, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->saveDraftSchema(array_merge($request->all(), ['resource' => $resource])));
    }

    public function activeVersion(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->getActiveVersion($resource));
    }

    public function syncCode(string $resource, Request $request, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->syncCodeResource(array_merge($request->all(), ['resource' => $resource])));
    }

    public function versions(string $resource, Request $request, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(static function () use ($resource, $request, $resources): mixed {
            $query = ['resource' => $resource];
            foreach (['page', 'limit'] as $field) {
                if ($request->query->has($field)) {
                    $query[$field] = $request->integer($field);
                }
            }

            return $resources->listVersions($query);
        });
    }

    public function version(string $resource, int $version, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(fn (): mixed => $resources->getVersion([
            'resource' => $resource,
            'version' => ['version_id' => $version],
        ]));
    }

    public function deleteDraft(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->respond(static function () use ($resource, $resources): array {
            $resources->deleteDraftResource($resource);

            return ['deleted' => true, 'resource' => $resource];
        });
    }

    public function enable(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->status($resource, $resources, 'enableResource');
    }

    public function disable(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->status($resource, $resources, 'disableResource');
    }

    public function archive(string $resource, ResourceServiceInterface $resources): JsonResponse
    {
        return $this->status($resource, $resources, 'archiveResource');
    }

    public function rebuildProjection(string $resource, \PTAdmin\Schema\Contracts\PublishServiceInterface $publish): JsonResponse
    {
        return $this->respond(fn (): mixed => $publish->rebuildFieldsProjection($resource));
    }

    private function status(string $resource, ResourceServiceInterface $resources, string $method): JsonResponse
    {
        return $this->respond(static function () use ($resource, $resources, $method): array {
            $resources->{$method}($resource);

            return ['resource' => $resource, 'status' => match ($method) {
                'enableResource' => 'active',
                'disableResource' => 'disabled',
                default => 'archived',
            }];
        });
    }
}
