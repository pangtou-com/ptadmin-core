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
        private ?RuntimeRegistry $runtimeRegistry = null,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?RuntimeBindingExecutor $runtimeExecutor = null,
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
        $context = $this->treeContext($query, $resolved);
        $parent = $query['parent_id'];
        $keyField = $this->keyField($resolved['schema']);
        if ($keyField !== 'id' && (int) $parent > 0) {
            $parent = DB::table($resolved['resource']->table_name)->where('id', (int) $parent)->value($keyField);
            if ($parent === null) {
                throw $this->error('record_not_found', 'record not found');
            }
        }

        return $this->nodesFromContext($context, $parent);
    }
    /** 以扁平 items 返回指定节点及后代，并携带 depth 与 ancestor_ids。 */
    public function getSubtree(array|object $query): array
    {
        $query = Payload::dto($query, GetTreeSubtreeQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $query = $this->treeContext($query, $resolved);
        $root = $this->node($query, $resolved);
        $rows = $this->descendantTreeRows($query, $resolved, $root->{$this->keyField($resolved['schema'])});
        $parentIds = $this->parentIdsForNodes($resolved['resource']->table_name, $this->keyField($resolved['schema']), [...$rows, $root]);
        $allChildCounts = $this->allChildCounts($query, $resolved, array_map(
            fn (object $row): mixed => $row->{$this->keyField($resolved['schema'])},
            $rows,
        ));
        $children = $this->groupNodesByParent($rows, $query, $allChildCounts, $parentIds);
        $items = [];
        if (!empty($query['include_root'])) {
            $items[] = $this->nodePayload($root, 0, [], $query, $parentIds);
        }
        $visited = [(int) $root->id => true];
        $items = $this->appendFlatChildrenFromMap(
            $items,
            $children,
            $root->{$this->keyField($resolved['schema'])},
            0,
            [],
            $this->keyField($resolved['schema']),
            $visited,
        );
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
        $query = $this->treeContext($query, $resolved);
        $root = null;
        if (array_key_exists('root_id', $query) && $query['root_id'] !== null) {
            if ((int) $query['root_id'] <= 0) {
                throw $this->error('invalid_input', 'tree root id is required');
            }
            $root = $this->node($query, $resolved);
        }
        $rows = $this->filteredTreeRows($query, $resolved);
        $parentRows = $root === null ? $rows : [...$rows, $root];
        $parentIds = $this->parentIdsForNodes($resolved['resource']->table_name, $this->keyField($resolved['schema']), $parentRows);
        $allChildCounts = $this->allChildCounts($query, $resolved);
        $children = $this->groupNodesByParent($rows, $query, $allChildCounts, $parentIds);
        if (array_key_exists('root_id', $query) && $query['root_id'] !== null) {
            $roots = !empty($query['include_root'])
                ? [$this->nodePayload($root, 0, [], $query, $parentIds)]
                : ($children[$this->treeValueKey($root->{$this->keyField($resolved['schema'])})] ?? []);
        } else {
            $roots = $children[$this->treeValueKey(null)] ?? [];
        }
        $branches = [];
        foreach ($roots as $root) {
            $branches[] = $this->buildTreeBranch($root, $query, $children, 0, $this->keyField($resolved['schema']));
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
        $query = $this->treeContext($query, $resolved);
        $current = $this->node($query, $resolved);
        $chainRows = $this->recursiveTreeRows(
            $resolved['resource']->table_name,
            $keyField,
            $parent,
            $current->{$keyField},
            false,
            $resolved['schema'],
            null,
            $resolved['resource']->resource_key,
        );
        $rowsByKey = [];
        foreach ($chainRows as $row) {
            $rowsByKey[(string) $row->{$keyField}] = $row;
        }
        $path = [];
        $seen = [];
        $currentKey = (string) $current->{$keyField};
        while (isset($rowsByKey[$currentKey])) {
            $current = $rowsByKey[$currentKey];
            if (isset($seen[(int) $current->id])) {
                break;
            }
            $seen[(int) $current->id] = true;
            $path[] = $current;
            $parentValue = $current->{$parent} ?? null;
            if ($this->isRootTreeValue($parentValue)) {
                break;
            }
            $currentKey = (string) $parentValue;
        }
        $parentIds = $this->parentIdsForNodes($resolved['resource']->table_name, $keyField, $path, $parent);
        $payloads = [];
        foreach ($path as $node) {
            $payloads[] = $this->nodePayload($node, count($payloads), [], $query, $parentIds);
        }
        if (empty($query['include_self'])) {
            array_shift($payloads);
        }
        return ['items' => array_reverse($payloads)];
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
            if ($newParentId !== null && (int) $newParentId > 0 && !$this->isAncestorSafe($resolved['resource']->table_name, $id, $newParent, $keyField, $parent, $resolved['schema'])) {
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
        $rows = DB::table($resolved['resource']->table_name)->get([$keyField, $parent, 'id']);
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
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        return $this->nodesFromContext($this->treeContext($query, $resolved), $parent);
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $resolved */
    private function treeContext(array|object $query, array $resolved): array
    {
        $query = Payload::array($query);
        $schema = $resolved['schema'];
        if (empty($schema->tree['enabled'])) {
            throw $this->error('action_not_available', 'tree is not enabled');
        }
        $query['parent_field'] = $this->parentField($schema);
        $query['schema'] = $schema->toArray();
        $query['schema_object'] = $schema;
        $query['table_name'] = $resolved['resource']->table_name;
        $query['resource'] = $resolved['resource']->resource_key;
        $query['has_soft_deletes'] = DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at');
        $query['has_order_field'] = DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, $this->orderField($schema));
        $query['runtime_snapshot'] = $this->loadRuntimeSnapshot();

        return $query;
    }

    /** @param array<string, mixed> $context */
    private function nodesFromContext(array $context, mixed $parent): array
    {
        return $this->nodesForParents($context, [$parent]);
    }

    /** @param array<string, mixed> $context @param list<mixed> $parents */
    private function nodesForParents(array $context, array $parents): array
    {
        $table = (string) $context['table_name'];
        $field = (string) $context['parent_field'];
        $schema = $context['schema_object'];
        $builder = DB::table($table);
        if (!empty($context['has_soft_deletes'])) {
            $builder->whereNull('deleted_at');
        }
        $this->applyParentValues($builder, $field, $parents);
        $this->applyFilters($builder, (array) ($context['filters'] ?? []));
        $this->applyStatePolicy($builder, $context, $schema);
        $this->applyScope($builder, (string) $context['resource'], 'list', $schema);
        if (!empty($context['has_order_field'])) {
            $builder->orderBy($this->orderField($schema));
        }
        $rows = $builder->orderBy('id')->get();

        return ['items' => $this->nodePayloads($rows->all(), $context)];
    }

    /** @param mixed $builder @param list<mixed> $parents */
    private function applyParentValues(mixed $builder, string $field, array $parents): void
    {
        $rootRequested = false;
        $values = [];
        foreach ($parents as $parent) {
            if ($this->isRootTreeValue($parent)) {
                $rootRequested = true;
            } else {
                $values[$this->treeValueKey($parent)] = $parent;
            }
        }
        $values = array_values($values);
        $builder->where(function ($nested) use ($field, $rootRequested, $values): void {
            $hasCondition = false;
            if ($rootRequested) {
                $nested->where(static function ($roots) use ($field): void {
                    $roots->whereNull($field)->orWhere($field, 0)->orWhere($field, '');
                });
                $hasCondition = true;
            }
            if ($values !== []) {
                if ($hasCondition) {
                    $nested->orWhereIn($field, $values);
                } else {
                    $nested->whereIn($field, $values);
                }
                $hasCondition = true;
            }
            if (!$hasCondition) {
                $nested->whereRaw('1 = 0');
            }
        });
    }

    /** @param list<object> $rows @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function nodePayloads(array $rows, array $context): array
    {
        $keyField = $this->keyField($context['schema_object']);
        $parentField = (string) $context['parent_field'];
        $parentIds = $this->parentIdsForNodes((string) $context['table_name'], $keyField, $rows, $parentField);
        $childCounts = $this->childCountsForParents(
            $context,
            array_map(static fn (object $row): mixed => $row->{$keyField}, $rows),
        );
        $items = [];
        foreach ($rows as $row) {
            $payload = $this->nodePayload($row, 0, [], $context, $parentIds);
            $payload['children_count'] = $childCounts[$this->treeValueKey($row->{$keyField})] ?? 0;
            $payload['has_children'] = $payload['children_count'] > 0;
            $items[] = $payload;
        }

        return $items;
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $resolved @return list<object> */
    private function filteredTreeRows(array $query, array $resolved): array
    {
        $builder = DB::table($resolved['resource']->table_name);
        if (!empty($query['has_soft_deletes'])) {
            $builder->whereNull('deleted_at');
        }
        $this->applyFilters($builder, (array) ($query['filters'] ?? []));
        $this->applyStatePolicy($builder, $query, $resolved['schema']);
        $this->applyScope($builder, $resolved['resource']->resource_key, 'list', $resolved['schema']);
        if (!empty($query['has_order_field'])) {
            $builder->orderBy($this->orderField($resolved['schema']));
        }

        return $builder->orderBy('id')->get()->all();
    }

    /**
     * 按递归父子关系读取目标节点的后代，避免加载无关树节点。
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $resolved
     * @return list<object>
     */
    private function descendantTreeRows(array $query, array $resolved, mixed $rootKey): array
    {
        return $this->recursiveTreeRows(
            (string) $resolved['resource']->table_name,
            $this->keyField($resolved['schema']),
            $this->parentField($resolved['schema']),
            $rootKey,
            true,
            $resolved['schema'],
            $query,
            $resolved['resource']->resource_key,
        );
    }

    /**
     * 在数据库中沿父子字段递归，只返回与起始节点连通的节点；UNION 去重可在历史数据存在环路时终止递归。
     * @param array<string, mixed>|null $query
     * @return list<object>
     */
    private function recursiveTreeRows(
        string $table,
        string $keyField,
        string $parentField,
        mixed $startValue,
        bool $descendants,
        object $schema,
        ?array $query = null,
        ?string $resourceKey = null,
    ): array {
        $grammar = DB::connection()->getQueryGrammar();
        $eligible = DB::table($table)->selectRaw(implode(', ', [
            $grammar->wrap('id').' as '.$grammar->wrap('node_id'),
            $grammar->wrap($keyField).' as '.$grammar->wrap('node_key'),
            $grammar->wrap($parentField).' as '.$grammar->wrap('parent_key'),
        ]));
        if ($query !== null) {
            if (!empty($query['has_soft_deletes'])) {
                $eligible->whereNull('deleted_at');
            }
            $this->applyFilters($eligible, (array) ($query['filters'] ?? []));
            $this->applyStatePolicy($eligible, $query, $schema);
        }
        if ($resourceKey !== null) {
            $this->applyScope($eligible, $resourceKey, 'list', $schema);
        }

        $eligibleName = $grammar->wrapTable('tree_eligible_nodes');
        $chainName = $grammar->wrapTable('tree_chain');
        $parentName = $grammar->wrapTable('tree_parent_nodes');
        $childName = $grammar->wrapTable('tree_child_nodes');
        $nodeId = $grammar->wrap('node_id');
        $nodeKey = $grammar->wrap('node_key');
        $parentKey = $grammar->wrap('parent_key');
        $baseColumns = implode(', ', [$nodeId, $nodeKey, $parentKey]);
        if ($descendants) {
            $anchor = 'select '.$baseColumns.' from '.$eligibleName.' where '.$parentKey.' = ?';
            $recursive = 'select '.$childName.'.'.$nodeId.', '.$childName.'.'.$nodeKey.', '.$childName.'.'.$parentKey
                .' from '.$eligibleName.' as '.$childName
                .' inner join '.$chainName.' as '.$parentName.' on '.$childName.'.'.$parentKey.' = '.$parentName.'.'.$nodeKey;
        } else {
            $anchor = 'select '.$baseColumns.' from '.$eligibleName.' where '.$nodeKey.' = ?';
            $recursive = 'select '.$parentName.'.'.$nodeId.', '.$parentName.'.'.$nodeKey.', '.$parentName.'.'.$parentKey
                .' from '.$eligibleName.' as '.$parentName
                .' inner join '.$chainName.' as '.$childName.' on '.$parentName.'.'.$nodeKey.' = '.$childName.'.'.$parentKey;
        }
        $source = DB::table($table.' as tree_source')
            ->join('tree_chain', 'tree_source.id', '=', 'tree_chain.node_id')
            ->select('tree_source.*');
        if ($query !== null && !empty($query['has_order_field'])) {
            $source->orderBy('tree_source.'.$this->orderField($schema));
        }
        $source->orderBy('tree_source.id');
        $sql = 'with recursive '.$eligibleName.' as ('.$eligible->toSql().'), '.$chainName.' as ('
            .$anchor.' union '.$recursive.') '.$source->toSql();

        return DB::select($sql, [...$eligible->getBindings(), $startValue, ...$source->getBindings()]);
    }

    /** @param list<object> $rows @param array<string, mixed> $context @param array<string, int> $allChildCounts @param array<string, int> $parentIds @return array<string, list<array<string, mixed>>> */
    private function groupNodesByParent(array $rows, array $context, array $allChildCounts, array $parentIds): array
    {
        $keyField = $this->keyField($context['schema_object']);
        $parentField = (string) $context['parent_field'];
        $grouped = [];
        foreach ($rows as $row) {
            $payload = $this->nodePayload($row, 0, [], $context, $parentIds);
            $parentKey = $this->treeValueKey($row->{$parentField} ?? null);
            $nodeKey = $this->treeValueKey($row->{$keyField});
            $payload['children_count'] = $allChildCounts[$nodeKey] ?? 0;
            $payload['has_children'] = $payload['children_count'] > 0;
            $grouped[$parentKey][] = $payload;
        }

        return $grouped;
    }

    /** @param list<object> $rows @param string $parentField @return array<string, int> */
    private function parentIdsForNodes(string $table, string $keyField, array $rows, string $parentField = 'parent_id'): array
    {
        $values = [];
        foreach ($rows as $row) {
            $parentValue = $row->{$parentField} ?? null;
            if (!$this->isRootTreeValue($parentValue)) {
                $values[$this->treeValueKey($parentValue)] = $parentValue;
            }
        }
        if ($values === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk(array_values($values), 500) as $chunk) {
            $rowsByKey = DB::table($table)
                ->whereIn($keyField, $chunk)
                ->get([$keyField, 'id']);
            foreach ($rowsByKey as $row) {
                $ids[$this->treeValueKey($row->{$keyField})] ??= (int) $row->id;
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $resolved @return array<string, int> */
    private function allChildCounts(array $query, array $resolved, ?array $parentValues = null): array
    {
        $field = $this->parentField($resolved['schema']);
        $counts = [];
        $chunks = $parentValues === null ? [null] : array_chunk(array_values(array_unique(array_filter($parentValues, static fn (mixed $value): bool => $value !== null), SORT_REGULAR)), 500);
        foreach ($chunks as $chunk) {
            $builder = DB::table($resolved['resource']->table_name)
                ->select($field)
                ->selectRaw('count(*) as aggregate');
            if ($chunk !== null) {
                $builder->whereIn($field, $chunk);
            }
            if (!empty($query['has_soft_deletes'])) {
                $builder->whereNull('deleted_at');
            }
            $this->applyScope($builder, $resolved['resource']->resource_key, 'list', $resolved['schema']);
            foreach ($builder->groupBy($field)->get() as $row) {
                $key = $this->treeValueKey($row->{$field} ?? null);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $context @param list<mixed> $parents @return array<string, int> */
    private function childCountsForParents(array $context, array $parents): array
    {
        $hasNull = in_array(null, $parents, true);
        $parents = array_values(array_unique(array_filter($parents, static fn (mixed $value): bool => $value !== null), SORT_REGULAR));
        if ($parents === [] && !$hasNull) {
            return [];
        }
        $counts = [];
        $field = (string) $context['parent_field'];
        foreach (array_chunk($parents, 500) as $chunk) {
            $builder = DB::table((string) $context['table_name'])
                ->select($field)
                ->selectRaw('count(*) as aggregate')
                ->whereIn($field, $chunk);
            if (!empty($context['has_soft_deletes'])) {
                $builder->whereNull('deleted_at');
            }
            $this->applyScope($builder, (string) $context['resource'], 'list', $context['schema_object']);
            foreach ($builder->groupBy($field)->get() as $row) {
                $key = $this->treeValueKey($row->{$field} ?? null);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
            }
        }
        if ($hasNull) {
            $builder = DB::table((string) $context['table_name'])
                ->select($field)
                ->selectRaw('count(*) as aggregate')
                ->whereNull($field);
            if (!empty($context['has_soft_deletes'])) {
                $builder->whereNull('deleted_at');
            }
            $this->applyScope($builder, (string) $context['resource'], 'list', $context['schema_object']);
            foreach ($builder->groupBy($field)->get() as $row) {
                $key = $this->treeValueKey($row->{$field} ?? null);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $branch @param array<string, mixed> $query @param array<string, list<array<string, mixed>>> $children @param array<int, bool> $visited @return array<string, mixed> */
    private function buildTreeBranch(array $branch, array $query, array $children, int $depth, string $keyField, array $visited = []): array
    {
        $maxDepth = (int) ($query['max_depth'] ?? 0);
        if ($maxDepth > 0 && $depth >= $maxDepth) {
            return $branch;
        }
        $nodeKey = $this->treeValueKey($branch['item'][$keyField] ?? $branch['id']);
        $directChildren = $children[$nodeKey] ?? [];
        $branch['children'] = [];
        $branch['children_count'] = count($directChildren);
        $branch['has_children'] = $directChildren !== [];
        $visited[(int) $branch['id']] = true;
        foreach ($directChildren as $child) {
            if (isset($visited[(int) $child['id']])) {
                continue;
            }
            $nested = $child;
            $nested['depth'] = $depth + 1;
            $nested['ancestor_ids'] = array_merge((array) ($branch['ancestor_ids'] ?? []), [(int) $branch['id']]);
            $branch['children'][] = $this->buildTreeBranch($nested, $query, $children, $depth + 1, $keyField, $visited);
        }

        return $branch;
    }

    /** @param list<array<string, mixed>> $items @param array<string, list<array<string, mixed>>> $children @param array<int, bool> $visited @return list<array<string, mixed>> */
    private function appendFlatChildrenFromMap(array $items, array $children, mixed $parent, int $depth, array $ancestorIds, string $keyField, array &$visited): array
    {
        foreach ($children[$this->treeValueKey($parent)] ?? [] as $child) {
            if (isset($visited[(int) $child['id']])) {
                continue;
            }
            $visited[(int) $child['id']] = true;
            $childAncestors = array_merge($ancestorIds, [(int) $child['parent_id']]);
            $child['depth'] = $depth + 1;
            $child['ancestor_ids'] = $childAncestors;
            $items[] = $child;
            $items = $this->appendFlatChildrenFromMap(
                $items,
                $children,
                $child['item'][$keyField] ?? $child['id'],
                $depth + 1,
                $childAncestors,
                $keyField,
                $visited,
            );
        }

        return $items;
    }

    /** @param array<string, mixed> $query @param array<string, mixed>|null $resolved */
    private function node(array $query, ?array $resolved = null): object
    {
        $resolved ??= $this->resolved((string) ($query['resource'] ?? ''));
        $builder = DB::table($resolved['resource']->table_name)->where('id', (int) ($query['id'] ?? $query['root_id'] ?? 0));
        if (!array_key_exists('has_soft_deletes', $query)
            ? DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')
            : !empty($query['has_soft_deletes'])) {
            $builder->whereNull('deleted_at');
        }
        $this->applyScope($builder, $resolved['resource']->resource_key, 'list', $resolved['schema']);
        $node = $builder->first();
        if (!$node) {
            throw $this->error('record_not_found', 'record not found');
        }
        return $node;
    }
    /** @param array<string, mixed> $context @param array<string, int>|null $parentIds */
    private function nodePayload(object $node, int $depth, array $ancestors, array $context = [], ?array $parentIds = null): array
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
            $snapshot = array_key_exists('runtime_snapshot', $context)
                ? $context['runtime_snapshot']
                : $this->loadRuntimeSnapshot();
            $hookContext = new RuntimeHookContext($resourceKey, 'output.transform', $schema, input: $item);
            if ($snapshot !== null && $this->runtimeExecutor !== null) {
                $resource = $snapshot->toArray()['resources'][$resourceKey] ?? null;
                $bindings = is_array($resource) ? ($resource['hooks']['output.transform'] ?? []) : [];
                if (is_array($bindings) && $bindings !== []) {
                    $item = $this->runtimeExecutor->transform($snapshot, $hookContext, $item);
                }
            }
        }

        $parentId = null;
        $table = (string) ($context['table_name'] ?? '');
        if (!$this->isRootTreeValue($parentValue) && $table !== '' && $schema instanceof Schema) {
            $parentId = $parentIds === null
                ? DB::table($table)->where($this->keyField($schema), $parentValue)->value('id')
                : ($parentIds[$this->treeValueKey($parentValue)] ?? null);
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

    private function treeValueKey(mixed $value): string
    {
        return $this->isRootTreeValue($value) ? 'root:' : 'value:'.(string) $value;
    }

    private function loadRuntimeSnapshot(): ?RuntimeRegistrySnapshot
    {
        if ($this->runtimeRegistry === null || $this->runtimeCache === null || $this->runtimeExecutor === null) {
            return null;
        }

        $registry = $this->runtimeRegistry;
        return $this->runtimeCache->load(static fn (): RuntimeRegistrySnapshot => $registry->build());
    }

    private function isAncestorSafe(string $table, int $nodeId, mixed $parentKey, string $keyField, string $parentField, object $schema): bool
    {
        $chain = $this->recursiveTreeRows($table, $keyField, $parentField, $parentKey, false, $schema);
        $reachesRoot = false;
        foreach ($chain as $ancestor) {
            if ((int) $ancestor->id === $nodeId) {
                return false;
            }
            if ($this->isRootTreeValue($ancestor->{$parentField} ?? null)) {
                $reachesRoot = true;
            }
        }

        return $reachesRoot;
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
