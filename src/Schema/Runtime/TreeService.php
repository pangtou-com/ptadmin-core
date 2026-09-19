<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Illuminate\Support\Facades\DB;
use PTAdmin\Schema\Contract\GetTreeAncestorsQuery;
use PTAdmin\Schema\Contract\GetTreeQuery;
use PTAdmin\Schema\Contract\GetTreeSubtreeQuery;
use PTAdmin\Schema\Contract\ListTreeChildrenQuery;
use PTAdmin\Schema\Contract\ListTreeRootsQuery;
use PTAdmin\Schema\Contract\MoveTreeNodeInput;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contracts\SchemaScopeResolverInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Support\Payload;

/**
 * 基于父字段实现树查询和节点移动，所有递归输出都受 max_depth 限制以避免异常数据导致无限遍历。
 */
final class TreeService implements \PTAdmin\Schema\Contracts\TreeServiceInterface
{
    public function __construct(
        private ?SchemaScopeResolverInterface $scopeResolver = null,
        private ?SchemaSubjectContextInterface $subjectContext = null,
        private ?ServerConstraintApplier $constraintApplier = null,
        private ?RuntimeResourceHookRegistry $hooks = null,
    ) {
        $this->constraintApplier ??= new ServerConstraintApplier();
    }

    /** 返回根节点，根节点以 parent_id 为空或零表示。 */
    public function listRoots(array|object $query): array
    {
        $query = Payload::dto($query, ListTreeRootsQuery::class);
        return $this->listNodes($query, null);
    }
    /** 返回指定 parent_id 下的直接子节点。 */
    public function listChildren(array|object $query): array
    {
        $query = Payload::dto($query, ListTreeChildrenQuery::class);
        if (!array_key_exists('parent_id', $query)) {
            throw $this->error('invalid_input', 'parent_id is required');
        }
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $parent = $query['parent_id'];
        $keyField = $this->keyField($resolved['schema']);
        if ($keyField !== 'id' && (int) $parent > 0) {
            $parent = DB::table($resolved['resource']->table_name)->where('id', (int) $parent)->value($keyField);
            if ($parent === null) {
                throw $this->error('record_not_found', 'record not found');
            }
        }

        return $this->listNodes($query, $parent);
    }
    /** 以扁平 items 返回指定节点及后代，并携带 depth 与 ancestor_ids。 */
    public function getSubtree(array|object $query): array
    {
        $query = Payload::dto($query, GetTreeSubtreeQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $query['parent_field'] = $this->parentField($resolved['schema']);
        $query['schema'] = $resolved['schema']->toArray();
        $query['schema_object'] = $resolved['schema'];
        $query['table_name'] = $resolved['resource']->table_name;
        $root = $this->node($query);
        $items = [];
        if (!empty($query['include_root'])) {
            $items[] = $this->nodePayload($root, 0, [], $query);
        }
        $this->appendFlatChildren($items, $query, $root->{$this->keyField($resolved['schema'])}, 0, $query);
        return ['items' => $items];
    }
    /** 以嵌套 children 返回整棵树，max_depth 控制递归边界。 */
    public function getTree(array|object $query): array
    {
        $query = Payload::dto($query, GetTreeQuery::class);
        if (($query['max_depth'] ?? 0) < 0) {
            throw $this->error('invalid_input', 'tree max depth cannot be negative');
        }
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $query['parent_field'] = $this->parentField($resolved['schema']);
        $query['schema'] = $resolved['schema']->toArray();
        $query['schema_object'] = $resolved['schema'];
        $query['table_name'] = $resolved['resource']->table_name;
        if (array_key_exists('root_id', $query) && $query['root_id'] !== null) {
            if ((int) $query['root_id'] <= 0) {
                throw $this->error('invalid_input', 'tree root id is required');
            }
            $root = $this->node($query);
            $roots = !empty($query['include_root'])
                ? [$this->nodePayload($root, 0, [], $query)]
                : $this->listNodes($query, $root->{$this->keyField($resolved['schema'])})['items'];
        } else {
            $roots = $this->listNodes($query, null)['items'];
        }
        $branches = [];
        foreach ($roots as $root) {
            $branch = $root;
            $this->appendChildren($branch, $query, 0);
            $branches[] = $branch;
        }
        return ['items' => $branches];
    }
    /** 沿 parent_field 向上读取祖先链，可通过 include_self 控制是否包含当前节点。 */
    public function getAncestors(array|object $query): array
    {
        $query = Payload::dto($query, GetTreeAncestorsQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $parent = $this->parentField($resolved['schema']);
        $keyField = $this->keyField($resolved['schema']);
        $query['parent_field'] = $parent;
        $query['schema'] = $resolved['schema']->toArray();
        $query['schema_object'] = $resolved['schema'];
        $query['table_name'] = $resolved['resource']->table_name;
        $current = $this->node($query);
        $path = [];
        while ($current) {
            array_unshift($path, $this->nodePayload($current, count($path), [], $query));
            $id = $current->{$parent} ?? null;
            if ($id === 0 || $id === '0' || $id === '') {
                $id = null;
            }
            if ($id === null) {
                $current = null;
                continue;
            }
            $ancestor = DB::table($resolved['resource']->table_name)->where($keyField, $id);
            $this->applyScope($ancestor, $resolved['resource']->resource_key, 'list', $resolved['schema']);
            $current = $ancestor->first();
        }
        if (empty($query['include_self'])) {
            array_pop($path);
        }
        return ['items' => $path];
    }
    /** 移动节点前阻止自环和移动到自身后代，避免破坏树图。 */
    public function moveNode(array|object $input): array
    {
        $input = Payload::dto($input, MoveTreeNodeInput::class);
        if (array_key_exists('before_id', $input) && array_key_exists('after_id', $input)) {
            throw $this->error('invalid_input', 'tree before_id and after_id cannot be used together');
        }
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $parent = $this->parentField($resolved['schema']);
        $keyField = $this->keyField($resolved['schema']);
        $input['parent_field'] = $parent;
        $input['schema'] = $resolved['schema']->toArray();
        $input['schema_object'] = $resolved['schema'];
        $input['table_name'] = $resolved['resource']->table_name;
        $id = (int) ($input['id'] ?? 0);
        $builder = DB::table($resolved['resource']->table_name)->where('id', $id);
        if (DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')) {
            $builder->whereNull('deleted_at');
        }
        $this->applyScope($builder, $resolved['resource']->resource_key, 'update', $resolved['schema']);
        if (!$builder->exists()) {
            throw $this->error('record_not_found', 'record not found');
        }
        if (array_key_exists('parent_id', $input)) {
            $newParentId = $input['parent_id'];
            if ($newParentId !== null && (int) $newParentId === $id) {
                throw $this->error('invalid_input', 'tree node cannot be its own parent');
            }
            $newParent = null;
            if ($newParentId !== null && (int) $newParentId > 0) {
                $parentBuilder = DB::table($resolved['resource']->table_name)->where('id', (int) $newParentId);
                $this->applyScope($parentBuilder, $resolved['resource']->resource_key, 'update', $resolved['schema']);
                $parentNode = $parentBuilder->first();
                if (!$parentNode) {
                    throw $this->error('record_not_found', 'tree parent not found');
                }
                $newParent = $parentNode->{$keyField};
            }
            if ($newParentId !== null && (int) $newParentId > 0 && !$this->isAncestorSafe($resolved['resource']->table_name, $id, (int) $newParentId, $keyField, $parent)) {
                throw $this->error('invalid_input', 'tree node cannot move under its descendant');
            }
            $builder->update([$parent => $newParent]);
        }
        $beforeId = $input['before_id'] ?? null;
        $afterId = $input['after_id'] ?? null;
        if ($beforeId !== null || $afterId !== null) {
            $anchorId = (int) ($beforeId ?? $afterId);
            if ($anchorId === $id) {
                throw $this->error('invalid_input', 'tree order anchor cannot be current node');
            }
            $anchorBuilder = DB::table($resolved['resource']->table_name)->where('id', $anchorId);
            $this->applyScope($anchorBuilder, $resolved['resource']->resource_key, 'update', $resolved['schema']);
            $anchor = $anchorBuilder->first();
            if (!$anchor) {
                throw $this->error('record_not_found', 'tree order anchor not found');
            }
            $node = DB::table($resolved['resource']->table_name)->where('id', $id)->first();
            if (!$this->sameTreeValue($anchor->{$parent} ?? null, $node->{$parent} ?? null)) {
                throw $this->error('invalid_input', 'tree order anchor must be a sibling');
            }
            $this->reorderSiblings(
                $resolved['resource']->table_name,
                $parent,
                $this->orderField($resolved['schema']),
                $node->{$parent} ?? null,
                $id,
                $anchorId,
                $beforeId !== null,
                $resolved['resource']->resource_key,
                $resolved['schema'],
            );
        }
        $node = DB::table($resolved['resource']->table_name)->where('id', $id)->first();
        return ['node' => $this->nodePayload($node, 0, [], $input)];
    }
    /** 扫描父引用图，发现孤儿引用或环路时拒绝重建。 */
    public function rebuildTree(string $resourceKey): array
    {
        $resolved = $this->resolved($resourceKey);
        if ($this->scopeFor($resourceKey, 'update') !== null) {
            throw $this->error('forbidden', 'scoped subjects cannot rebuild a complete tree');
        }
        $parent = $this->parentField($resolved['schema']);
        $keyField = $this->keyField($resolved['schema']);
        $rows = DB::table($resolved['resource']->table_name)->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->{$keyField}] = $row->{$parent} ?? null;
        }
        foreach ($map as $key => $parentKey) {
            $seen = [$key => true];
            while (!$this->isRootTreeValue($parentKey)) {
                $parentKey = (string) $parentKey;
                if (!array_key_exists($parentKey, $map) || isset($seen[$parentKey])) {
                    throw $this->error('invalid_input', 'tree parent graph is invalid');
                }
                $seen[$parentKey] = true;
                $parentKey = $map[$parentKey];
            }
        }
        return ['resource' => $resolved['resource']->resource_key, 'count' => count($map)];
    }

    private function listNodes(array|object $query, mixed $parent): array
    {
        $query = Payload::array($query);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        if (empty($resolved['schema']->tree['enabled'])) {
            throw $this->error('action_not_available', 'tree is not enabled');
        }
        $field = $this->parentField($resolved['schema']);
        $keyField = $this->keyField($resolved['schema']);
        $query['parent_field'] = $field;
        $query['schema'] = $resolved['schema']->toArray();
        $query['schema_object'] = $resolved['schema'];
        $query['table_name'] = $resolved['resource']->table_name;
        $builder = DB::table($resolved['resource']->table_name);
        $hasSoftDeletes = DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at');
        if ($hasSoftDeletes) {
            $builder->whereNull('deleted_at');
        }
        if ($this->isRootTreeValue($parent)) {
            $builder->where(static function ($nested) use ($field): void {
                $nested->whereNull($field)->orWhere($field, 0)->orWhere($field, '');
            });
        } else {
            $builder->where($field, $parent);
        }
        $this->applyFilters($builder, (array) ($query['filters'] ?? []));
        $this->applyStatePolicy($builder, $query, $resolved['schema']);
        $this->applyScope($builder, $resolved['resource']->resource_key, 'list', $resolved['schema']);
        $order = $this->orderField($resolved['schema']);
        if (DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, $order)) {
            $builder->orderBy($order);
        }
        $table = $resolved['resource']->table_name;
        $items = $builder->orderBy('id')->get()->map(function ($row) use ($query, $table, $field, $keyField, $hasSoftDeletes): array {
            $payload = $this->nodePayload($row, 0, [], $query);
            $children = DB::table($table)->where($field, $row->{$keyField});
            if ($hasSoftDeletes) {
                $children->whereNull('deleted_at');
            }
            $this->applyScope($children, (string) $query['resource'], 'list', $query['schema_object']);
            $payload['children_count'] = $children->count();
            $payload['has_children'] = $payload['children_count'] > 0;

            return $payload;
        })->all();
        return ['items' => $items];
    }
    private function appendChildren(array &$branch, array $query, int $depth): void
    {
        $maxDepth = (int) ($query['max_depth'] ?? 0);
        if ($maxDepth > 0 && $depth >= $maxDepth) {
            return;
        }
        $keyField = $this->keyField($query['schema_object']);
        $children = $this->listNodes($query, $branch['item'][$keyField] ?? $branch['id'])['items'];
        $branch['children'] = [];
        $branch['children_count'] = count($children);
        $branch['has_children'] = $children !== [];
        foreach ($children as $child) {
            $nested = $child;
            $nested['depth'] = $depth + 1;
            $nested['ancestor_ids'] = array_merge((array) ($branch['ancestor_ids'] ?? []), [(int) $branch['id']]);
            $this->appendChildren($nested, $query, $depth + 1);
            $branch['children'][] = $nested;
        }
    }
    private function appendFlatChildren(array &$items, array $query, mixed $parent, int $depth, array $context): void
    {
        $maxDepth = (int) ($query['max_depth'] ?? 0);
        if ($maxDepth > 0 && $depth >= $maxDepth) {
            return;
        }
        foreach ($this->listNodes($query, $parent)['items'] as $child) {
            $ancestors = array_merge((array) ($context['ancestor_ids'] ?? []), [(int) $child['parent_id']]);
            $payload = $child;
            $payload['depth'] = $depth + 1;
            $payload['ancestor_ids'] = $ancestors;
            $items[] = $payload;
            $nextContext = $context;
            $nextContext['ancestor_ids'] = $ancestors;
            $keyField = $this->keyField($query['schema_object']);
            $this->appendFlatChildren($items, $query, $payload['item'][$keyField] ?? $payload['id'], $depth + 1, $nextContext);
        }
    }
    private function node(array $query): object
    {
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $builder = DB::table($resolved['resource']->table_name)->where('id', (int) ($query['id'] ?? $query['root_id'] ?? 0));
        if (DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')) {
            $builder->whereNull('deleted_at');
        }
        $this->applyScope($builder, $resolved['resource']->resource_key, 'list', $resolved['schema']);
        $node = $builder->first();
        if (!$node) {
            throw $this->error('record_not_found', 'record not found');
        }
        return $node;
    }
    private function nodePayload(object $node, int $depth, array $ancestors, array $context = []): array
    {
        $parentField = (string) ($context['parent_field'] ?? 'parent_id');
        $parentValue = $node->{$parentField} ?? null;
        $item = (array) $node;
        $fields = [];
        foreach ((array) (($context['schema'] ?? [])['fields'] ?? []) as $field) {
            $fields[(string) ($field['name'] ?? '')] = (string) ($field['type'] ?? '');
        }
        foreach ($item as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (($fields[$name] ?? '') === 'json' && is_string($value)) {
                $decoded = json_decode($value, true);
                $item[$name] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            } elseif (($fields[$name] ?? '') === 'boolean') {
                $item[$name] = (bool) $value;
            } elseif (($fields[$name] ?? '') === 'integer') {
                $item[$name] = (int) $value;
            }
        }

        $schema = $context['schema_object'] ?? null;
        $resourceKey = (string) ($context['resource'] ?? '');
        if ($schema instanceof Schema && $resourceKey !== '') {
            $hook = $this->hooks?->get($resourceKey);
            $item = $hook ? $hook->output($resourceKey, $item, $schema) : $item;
        }

        $parentId = null;
        $table = (string) ($context['table_name'] ?? '');
        if (!$this->isRootTreeValue($parentValue) && $table !== '' && $schema instanceof Schema) {
            $parentId = DB::table($table)->where($this->keyField($schema), $parentValue)->value('id');
        }

        return ['id' => (int) $node->id, 'parent_id' => $parentId === null ? null : (int) $parentId, 'depth' => $depth, 'has_children' => false, 'children_count' => 0, 'ancestor_ids' => $ancestors, 'item' => $item];
    }
    private function keyField(object $schema): string
    {
        return trim((string) (($schema->tree['key_field'] ?? '') ?: 'id'));
    }
    private function parentField(object $schema): string
    {
        return trim((string) (($schema->tree['parent_field'] ?? '') ?: 'parent_id'));
    }
    private function orderField(object $schema): string
    {
        return trim((string) (($schema->tree['order_field'] ?? '') ?: 'sort'));
    }
    private function isAncestorSafe(string $table, int $nodeId, int $parentId, string $keyField, string $parentField): bool
    {
        $seen = [$nodeId => true];
        $current = $parentId;
        while ($current > 0) {
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;
            $row = DB::table($table)->where('id', $current)->first();
            if (!$row) {
                return false;
            }
            $next = $row->{$parentField} ?? null;
            if ($this->isRootTreeValue($next)) {
                return true;
            }
            $nextId = DB::table($table)->where($keyField, $next)->value('id');
            if ($nextId === null) {
                return false;
            }
            $current = (int) $nextId;
        }
        return true;
    }

    private function isRootTreeValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }

    private function sameTreeValue(mixed $left, mixed $right): bool
    {
        if ($this->isRootTreeValue($left) && $this->isRootTreeValue($right)) {
            return true;
        }

        return (string) $left === (string) $right;
    }

    /** @param list<array<string, mixed>> $filters */
    private function applyFilters($builder, array $filters): void
    {
        foreach ($filters as $filter) {
            $filter = Payload::array($filter);
            $field = (string) ($filter['field'] ?? '');
            $operator = strtolower((string) ($filter['operator'] ?? 'eq'));
            if ($field === '') {
                throw $this->error('invalid_input', 'tree filter field is required');
            }
            $value = $filter['value'] ?? null;
            if ($operator === 'in' || $operator === 'not_in') {
                $builder->{$operator === 'in' ? 'whereIn' : 'whereNotIn'}($field, (array) $value);
            } elseif ($operator === 'is_null' || $operator === 'not_null') {
                $builder->{$operator === 'is_null' ? 'whereNull' : 'whereNotNull'}($field);
            } else {
                $sqlOperator = ['eq' => '=', 'ne' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'like' => 'like'][$operator] ?? null;
                if ($sqlOperator === null) {
                    throw $this->error('invalid_input', 'tree filter operator is not supported');
                }
                $builder->where($field, $sqlOperator, $value);
            }
        }
    }

    /**
     * state_kinds 会转换为状态字段值过滤；调用方必须显式声明 filter 策略，避免树结构被隐式裁剪。
     */
    private function applyStatePolicy($builder, array $query, object $schema): void
    {
        $kinds = (array) ($query['state_kinds'] ?? []);
        $policy = (string) ($query['state_policy'] ?? '');
        if ($kinds === []) {
            if ($policy !== '' && $policy !== 'filter') {
                throw $this->error('invalid_input', 'tree state policy is not supported');
            }
            return;
        }
        if ($policy !== 'filter') {
            throw $this->error('invalid_input', 'tree state policy is required');
        }
        $state = (array) ($schema->state ?? []);
        $field = (string) ($state['field'] ?? '');
        if ($field === '') {
            throw $this->error('invalid_input', 'resource state is not configured');
        }
        $values = [];
        foreach ((array) ($state['values'] ?? []) as $value => $definition) {
            $definition = (array) $definition;
            if (in_array((string) ($definition['kind'] ?? ''), $kinds, true)) {
                $values[] = $value;
            }
        }
        $values === [] ? $builder->whereRaw('1 = 0') : $builder->whereIn($field, $values);
    }

    private function reorderSiblings(
        string $table,
        string $parentField,
        string $orderField,
        mixed $parent,
        int $nodeId,
        int $anchorId,
        bool $before,
        string $resourceKey,
        object $schema,
    ): void
    {
        if (!DB::getSchemaBuilder()->hasColumn($table, $orderField)) {
            throw $this->error('action_not_available', 'tree order field is not available');
        }
        DB::transaction(function () use ($table, $parentField, $orderField, $parent, $nodeId, $anchorId, $before, $resourceKey, $schema): void {
            $siblingsQuery = DB::table($table)
                ->where(function ($builder) use ($parentField, $parent): void {
                    if ($this->isRootTreeValue($parent)) {
                        $builder->whereNull($parentField)->orWhere($parentField, 0)->orWhere($parentField, '');
                        return;
                    }
                    $builder->where($parentField, $parent);
                });
            $this->applyScope($siblingsQuery, $resourceKey, 'update', $schema);
            $siblings = $siblingsQuery->orderBy($orderField)->orderBy('id')->lockForUpdate()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $siblings = array_values(array_filter($siblings, static fn (int $id): bool => $id !== $nodeId));
            $position = array_search($anchorId, $siblings, true);
            if ($position === false) {
                throw $this->error('invalid_input', 'tree order anchor must be a sibling');
            }
            array_splice($siblings, $position + ($before ? 0 : 1), 0, [$nodeId]);
            foreach ($siblings as $index => $id) {
                DB::table($table)->where('id', $id)->update([$orderField => $index + 1]);
            }
        });
    }
    private function resolved(string $key): array
    {
        $resource = Resource::query()->where('resource_key', trim($key))->first();
        if (!$resource) {
            throw $this->error('draft_not_found', 'resource not found');
        }
        $version = $resource->versions()->where('status', 'active')->first();
        if (!$version) {
            throw $this->error('active_version_not_found', 'active version not found');
        }
        return ['resource' => $resource, 'schema' => new Schema((array) $version->schema_json)];
    }

    private function applyScope(mixed $builder, string $resourceKey, string $action, object $schema): void
    {
        $this->constraintApplier?->apply($builder, $this->scopeFor($resourceKey, $action), $schema);
    }

    private function scopeFor(string $resourceKey, string $action): mixed
    {
        if ($this->scopeResolver === null || $this->subjectContext === null) {
            return null;
        }

        return $this->scopeResolver->resolve($this->subjectContext->get(), $resourceKey, $action);
    }

    private function error(string $code, string $message): SchemaError
    {
        return new SchemaError($code, $message, [], [], false, 'runtime');
    }
}
