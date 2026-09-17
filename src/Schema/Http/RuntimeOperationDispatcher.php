<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Http;

use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contracts\RuntimeEngineInterface;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Contracts\TreeServiceInterface;
use PTAdmin\Schema\Meta\Models\FieldProjection;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Render\Projector;

/**
 * 将 SparqJS 统一 Runtime Transport 转换为后端 Runtime 与 TreeService 调用。
 */
final class RuntimeOperationDispatcher
{
    public function __construct(
        private RuntimeEngineInterface $runtime,
        private TreeServiceInterface $trees,
        private SchemaAuthorizationInterface $authorization,
        private SchemaSubjectContextInterface $subjectContext,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function dispatch(string $operation, array $context): mixed
    {
        $resource = trim((string) ($context['resource'] ?? ''));
        if ($resource === '') {
            throw $this->error('invalid_input', 'Schema Runtime 请求缺少资源编码。');
        }

        return match ($operation) {
            'loadSchema' => $this->loadSchema($resource),
            'list' => $this->runtime->list($this->listInput($resource, $context)),
            'detail' => $this->runtime->detail([
                'resource' => $resource,
                'id' => (int) ($context['id'] ?? 0),
            ])['item'],
            'create' => $this->runtime->create([
                'resource' => $resource,
                'data' => (array) ($context['values'] ?? []),
                'mode' => (string) ($context['mode'] ?? 'submit'),
            ])['item'],
            'update' => $this->runtime->update([
                'resource' => $resource,
                'id' => (int) ($context['id'] ?? 0),
                'data' => (array) ($context['values'] ?? []),
                'mode' => (string) ($context['mode'] ?? 'submit'),
            ])['item'],
            'remove' => $this->runtime->delete([
                'resource' => $resource,
                'id' => (int) ($context['id'] ?? 0),
            ]),
            'restore' => $this->runtime->restore([
                'resource' => $resource,
                'id' => (int) ($context['id'] ?? 0),
            ]),
            'purge' => $this->runtime->purge([
                'resource' => $resource,
                'id' => (int) ($context['id'] ?? 0),
            ]),
            'executeAction' => $this->runtime->executeAction($this->actionInput($resource, $context)),
            'loadOptions', 'searchRelation' => $this->relationOptions($resource, $context),
            'getTree' => $this->treeOperation($resource, 'list', fn (): array => $this->trees->getTree($this->treeInput($resource, $context) + [
                'root_id' => $context['rootId'] ?? null,
                'include_root' => (bool) ($context['includeRoot'] ?? false),
                'max_depth' => (int) ($context['maxDepth'] ?? 0),
            ])),
            'treeRoots' => $this->treeOperation($resource, 'list', fn (): array => $this->trees->listRoots($this->treeInput($resource, $context))),
            'treeChildren' => $this->treeOperation($resource, 'list', fn (): array => $this->trees->listChildren($this->treeInput($resource, $context) + [
                'parent_id' => (int) ($context['parentId'] ?? 0),
            ])),
            'treeSubtree' => $this->treeOperation($resource, 'list', fn (): array => $this->trees->getSubtree($this->treeInput($resource, $context) + [
                'root_id' => (int) ($context['rootId'] ?? 0),
                'include_root' => (bool) ($context['includeRoot'] ?? false),
            ])),
            'treeAncestors' => $this->treeOperation($resource, 'list', fn (): array => $this->trees->getAncestors($this->treeInput($resource, $context) + [
                'id' => (int) ($context['id'] ?? 0),
                'include_self' => (bool) ($context['includeSelf'] ?? false),
            ])),
            'moveTreeNode' => $this->treeOperation($resource, 'update', fn (): array => $this->trees->moveNode($this->moveTreeInput($resource, $context))),
            'rebuildTree' => $this->treeOperation($resource, 'update', fn (): array => $this->trees->rebuildTree($resource)),
            default => throw $this->error('invalid_input', 'Schema Runtime 不支持该操作。', ['operation' => $operation]),
        };
    }

    /** @return array<string, mixed> */
    private function loadSchema(string $resourceKey): array
    {
        $this->runtime->schemaSnapshot(['resource' => $resourceKey]);
        $resource = Resource::query()->where('resource_key', $resourceKey)->firstOrFail();
        $version = $resource->versions()->where('status', 'active')->firstOrFail();
        $fields = FieldProjection::query()
            ->where('resource_id', $resource->id)
            ->where('version_id', $version->id)
            ->orderBy('sort')
            ->get();

        return Projector::project($resource, $version, $fields);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function listInput(string $resource, array $context): array
    {
        $pagination = (array) ($context['pagination'] ?? []);
        $sort = $context['sort'] ?? [];
        $sorts = is_array($sort) && array_is_list($sort) ? $sort : ($sort === [] ? [] : [$sort]);

        return [
            'resource' => $resource,
            'filters' => array_merge((array) ($context['filters'] ?? []), $this->searchFilters((array) ($context['query'] ?? []))),
            'sorts' => $sorts,
            'page' => (int) ($pagination['page'] ?? 1),
            'limit' => (int) ($pagination['pageSize'] ?? 20),
            'recycle_mode' => (string) ($context['recycleMode'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $query @return list<array{field:string,operator:string,value:mixed}> */
    private function searchFilters(array $query): array
    {
        $filters = [];
        foreach ($query as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $filters[] = [
                'field' => (string) $field,
                'operator' => is_array($value) ? 'in' : (is_string($value) ? 'contains' : 'eq'),
                'value' => $value,
            ];
        }

        return $filters;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function actionInput(string $resource, array $context): array
    {
        return [
            'resource' => $resource,
            'action' => (string) ($context['action'] ?? ''),
            'id' => (int) ($context['id'] ?? 0),
            'ids' => $this->selectionIds($context['selection'] ?? []),
            'data' => (array) ($context['formValues'] ?? []),
        ];
    }

    /** @return list<int> */
    private function selectionIds(mixed $selection): array
    {
        if (!is_array($selection)) {
            throw $this->error('invalid_input', 'Schema Runtime 批量操作的选择项格式不正确。', ['field' => 'selection']);
        }

        $ids = [];
        foreach ($selection as $selected) {
            $value = is_array($selected)
                ? ($selected['id'] ?? null)
                : (is_object($selected) ? ($selected->id ?? null) : $selected);
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
                throw $this->error('invalid_input', 'Schema Runtime 批量操作的选择项缺少有效主键。', ['field' => 'selection']);
            }
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function relationOptions(string $resource, array $context): array
    {
        $result = $this->runtime->relationCandidates([
            'resource' => $resource,
            'field' => (string) ($context['field'] ?? ''),
            'keyword' => (string) ($context['keyword'] ?? ''),
            'page' => (int) ($context['page'] ?? 1),
            'limit' => (int) ($context['limit'] ?? 20),
            'values' => (array) ($context['values'] ?? []),
            'parent_value' => $context['parentValue'] ?? null,
            'params' => (array) ($context['params'] ?? []),
            'form_values' => (array) ($context['formValues'] ?? []),
        ]);

        $options = [];
        $seen = [];
        foreach (array_merge((array) ($result['selected'] ?? []), (array) ($result['results'] ?? [])) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $key = get_debug_type($option['value'] ?? null).':'.json_encode($option['value'] ?? null, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $options[] = $option;
        }

        return $options;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function treeInput(string $resource, array $context): array
    {
        return [
            'resource' => $resource,
            'filters' => (array) ($context['filters'] ?? []),
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function moveTreeInput(string $resource, array $context): array
    {
        $input = ['resource' => $resource, 'id' => (int) ($context['id'] ?? 0)];
        foreach (['parentId' => 'parent_id', 'beforeId' => 'before_id', 'afterId' => 'after_id'] as $source => $target) {
            if (array_key_exists($source, $context)) {
                $input[$target] = $context[$source];
            }
        }

        return $input;
    }

    /** @param callable(): array<string, mixed> $operation @return array<string, mixed> */
    private function treeOperation(string $resource, string $ability, callable $operation): array
    {
        if (!$this->authorization->allows($this->subjectContext->get(), $resource, $ability)) {
            throw $this->error('forbidden', 'Schema 树资源操作未获授权。', [
                'resource_key' => $resource,
                'action' => $ability,
            ]);
        }

        return $operation();
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details);
    }
}
