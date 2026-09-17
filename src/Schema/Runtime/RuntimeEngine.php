<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Illuminate\Support\Facades\DB;
use PTAdmin\Schema\Contract\CreateInput;
use PTAdmin\Schema\Contract\DeleteInput;
use PTAdmin\Schema\Contract\DetailQuery;
use PTAdmin\Schema\Contract\ExecuteActionInput;
use PTAdmin\Schema\Contract\ListQuery;
use PTAdmin\Schema\Contract\PurgeInput;
use PTAdmin\Schema\Contract\RelationCandidatesQuery;
use PTAdmin\Schema\Contract\RestoreInput;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contract\SchemaSnapshotQuery;
use PTAdmin\Schema\Contract\UpdateInput;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaScopeResolverInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Support\Payload;

/**
 * Schema 运行时执行器。它把协议输入转换为数据库查询，并集中处理字段校验、生成器、脱敏和服务端约束。
 */
final class RuntimeEngine implements \PTAdmin\Schema\Contracts\RuntimeEngineInterface
{
    private ?object $executor;

    /**
     * @param object|null $executor 宿主动作执行器；未注入时仅支持包内内置动作。
     */
    public function __construct(
        ?object $executor = null,
        ?SchemaAuthorizationInterface $authorization = null,
        ?SchemaScopeResolverInterface $scopeResolver = null,
        ?SchemaSubjectContextInterface $subjectContext = null,
        ?RuntimeResourceHookRegistry $hooks = null,
        ?ServerConstraintApplier $constraintApplier = null,
    )
    {
        $this->executor = $executor;
        $this->authorization = $authorization;
        $this->scopeResolver = $scopeResolver;
        $this->subjectContext = $subjectContext;
        $this->hooks = $hooks;
        $this->constraintApplier = $constraintApplier ?? new ServerConstraintApplier();
    }

    private ?SchemaAuthorizationInterface $authorization = null;
    private ?SchemaScopeResolverInterface $scopeResolver = null;
    private ?SchemaSubjectContextInterface $subjectContext = null;
    private ?RuntimeResourceHookRegistry $hooks = null;
    private ServerConstraintApplier $constraintApplier;

    /** @param array<string, mixed>|object $query */
    public function list(array|object $query): array
    {
        $query = Payload::dto($query, ListQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'list');
        $schema = $resolved['schema'];
        $resource = $resolved['resource'];
        $recycleMode = strtolower(trim((string) ($query['recycle_mode'] ?? '')));
        if (in_array($recycleMode, ['only', 'with'], true) && empty($schema->capabilities['allow_recycle'])) {
            throw $this->error('invalid_input', 'recycle query is not enabled for resource');
        }
        $builder = DB::table($resource->table_name);
        $this->applyRecycle($builder, $resource, $recycleMode);
        $this->applyStateKinds($builder, $query['state_kinds'] ?? [], $schema);
        $this->applyFilters($builder, (array) ($query['filters'] ?? []), $schema);
        $this->applySorts($builder, (array) ($query['sorts'] ?? []), $schema);
        $this->applyScope($builder, $query['server_constraint'] ?? null, $schema);
        $this->applyScope($builder, $this->scopeFor($resource->resource_key, 'list'), $schema);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(500, (int) ($query['limit'] ?? 20)));
        $total = (clone $builder)->count();
        $rows = $builder->forPage($page, $limit)->get()->map(fn ($row): array => $this->output((array) $row, $schema, $resource->resource_key))->all();
        return ['results' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** @param array<string, mixed>|object $query */
    public function getAll(array|object $query, ?int $max = null): array
    {
        $query = Payload::dto($query, ListQuery::class);
        $query['page'] = 1;
        $query['limit'] = $max ?? 500;
        return $this->list($query)['results'];
    }

    /** @param array<string, mixed>|object $query */
    public function first(array|object $query): array
    {
        $query = Payload::dto($query, ListQuery::class);
        $query['page'] = 1;
        $query['limit'] = 1;
        $result = $this->list($query);
        return ['item' => $result['results'][0] ?? []];
    }

    /** @param array<string, mixed>|object $query */
    public function detail(array|object $query): array
    {
        $query = Payload::dto($query, DetailQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'view');
        $detailBuilder = DB::table($resolved['resource']->table_name)->where('id', (int) ($query['id'] ?? 0));
        $this->applyRecycle($detailBuilder, $resolved['resource'], (string) ($query['recycle_mode'] ?? ''));
        $this->applyScope($detailBuilder, $query['server_constraint'] ?? null, $resolved['schema']);
        $this->applyScope($detailBuilder, $this->scopeFor($resolved['resource']->resource_key, 'view'), $resolved['schema']);
        $row = $detailBuilder->first();
        if (!$row) {
            throw $this->error('record_not_found', 'record not found', ['resource_key' => $query['resource'] ?? '', 'id' => $query['id'] ?? 0]);
        }
        return ['item' => $this->output((array) $row, $resolved['schema'], $resolved['resource']->resource_key)];
    }

    public function find(string $resourceKey, int $id): array
    {
        return $this->detail(['resource' => $resourceKey, 'id' => $id]);
    }

    /** @param array<string, mixed>|object $input */
    public function create(array|object $input): array
    {
        $input = Payload::dto($input, CreateInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'create');
        $raw = (array) ($input['data'] ?? []);
        $data = $this->prepareInput($raw, $resolved['schema'], true, (string) ($input['mode'] ?? 'submit'), $resolved['resource']);
        $hook = $this->hooks?->get($resolved['resource']->resource_key);
        if ($hook) {
            $data = $hook->prepareCreate($resolved['resource']->resource_key, $raw, $data, $resolved['schema']);
        }
        $scope = $this->scopeFor($resolved['resource']->resource_key, 'create');
        if (!$this->constraintApplier->matches($data, $scope, $resolved['schema'])) {
            throw $this->error('forbidden', 'created record is outside the authorized data scope');
        }
        $id = DB::transaction(function () use ($resolved, $data, $hook, $raw): int {
            $id = (int) DB::table($resolved['resource']->table_name)->insertGetId($data);
            $hook?->created($resolved['resource']->resource_key, $id, $raw, $resolved['schema']);

            return $id;
        });
        return ['id' => (int) $id, 'item' => $this->find((string) $input['resource'], (int) $id)['item']];
    }

    /** @param array<string, mixed>|object $input */
    public function update(array|object $input): array
    {
        $input = Payload::dto($input, UpdateInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'update');
        $id = (int) ($input['id'] ?? 0);
        $builder = DB::table($resolved['resource']->table_name)->where('id', $id);
        $this->applyRecycle($builder, $resolved['resource'], '');
        $serverConstraint = $input['server_constraint'] ?? null;
        $scope = $this->scopeFor($resolved['resource']->resource_key, 'update');
        $this->applyScope($builder, $serverConstraint, $resolved['schema']);
        $this->applyScope($builder, $scope, $resolved['schema']);
        $current = $builder->first();
        if (!$current) {
            throw $this->error('record_not_found', 'record not found', ['id' => $id]);
        }
        $raw = (array) ($input['data'] ?? []);
        $data = $this->prepareInput($raw, $resolved['schema'], false, (string) ($input['mode'] ?? 'submit'), $resolved['resource'], $id);
        $hook = $this->hooks?->get($resolved['resource']->resource_key);
        if ($hook) {
            $data = $hook->prepareUpdate($resolved['resource']->resource_key, $id, $raw, $data, $resolved['schema']);
        }
        $next = array_merge((array) $current, $data);
        if (!$this->constraintApplier->matches($next, $serverConstraint, $resolved['schema'])
            || !$this->constraintApplier->matches($next, $scope, $resolved['schema'])
        ) {
            throw $this->error('forbidden', 'updated record is outside the authorized data scope');
        }
        DB::transaction(function () use ($builder, $data, $hook, $resolved, $id, $raw): void {
            if ($data !== []) {
                $builder->update($data);
            }
            $hook?->updated($resolved['resource']->resource_key, $id, $raw, $resolved['schema']);
        });
        return ['id' => $id, 'changed' => array_keys($data), 'item' => $this->find((string) $input['resource'], $id)['item']];
    }

    /** @param array<string, mixed>|object $input */
    public function delete(array|object $input): array
    {
        $input = Payload::dto($input, DeleteInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'delete');
        if (empty($resolved['schema']->capabilities['allow_recycle']) && DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')) {
            throw $this->error('invalid_input', 'recycle is not enabled for resource');
        }
        $builder = DB::table($resolved['resource']->table_name)->where('id', (int) ($input['id'] ?? 0));
        $this->applyRecycle($builder, $resolved['resource'], '');
        $this->applyScope($builder, $input['server_constraint'] ?? null, $resolved['schema']);
        $this->applyScope($builder, $this->scopeFor($resolved['resource']->resource_key, 'delete'), $resolved['schema']);
        if (!$builder->exists()) {
            throw $this->error('record_not_found', 'record not found');
        }
        $id = (int) ($input['id'] ?? 0);
        $hook = $this->hooks?->get($resolved['resource']->resource_key);
        DB::transaction(function () use ($resolved, $builder, $hook, $id): void {
            $hook?->deleting($resolved['resource']->resource_key, $id, $resolved['schema']);
            if (!empty($resolved['schema']->capabilities['allow_recycle']) && DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')) {
                $builder->update(['deleted_at' => now()]);
            } else {
                $builder->delete();
            }
            $hook?->deleted($resolved['resource']->resource_key, $id, $resolved['schema']);
        });
        return ['success' => true, 'affected' => 1];
    }

    /** @param array<string, mixed>|object $input */
    public function restore(array|object $input): array
    {
        $input = Payload::dto($input, RestoreInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'restore');
        if (empty($resolved['schema']->capabilities['allow_recycle'])) {
            throw $this->error('action_not_available', 'restore is not available');
        }
        $restoreBuilder = DB::table($resolved['resource']->table_name)->where('id', (int) ($input['id'] ?? 0));
        $this->applyScope($restoreBuilder, $input['server_constraint'] ?? null, $resolved['schema']);
        $this->applyScope($restoreBuilder, $this->scopeFor($resolved['resource']->resource_key, 'restore'), $resolved['schema']);
        if (DB::getSchemaBuilder()->hasColumn($resolved['resource']->table_name, 'deleted_at')) {
            $restoreBuilder->whereNotNull('deleted_at');
        }
        $affected = $restoreBuilder->update(['deleted_at' => null]);
        if ($affected === 0) {
            throw $this->error('record_not_found', 'record not found');
        }
        return ['success' => true, 'affected' => $affected];
    }

    /** @param array<string, mixed>|object $input */
    public function purge(array|object $input): array
    {
        $input = Payload::dto($input, PurgeInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'purge');
        if (empty($resolved['schema']->capabilities['allow_recycle'])) {
            throw $this->error('action_not_available', 'purge is not available');
        }
        $purgeBuilder = DB::table($resolved['resource']->table_name)->where('id', (int) ($input['id'] ?? 0));
        $this->applyScope($purgeBuilder, $input['server_constraint'] ?? null, $resolved['schema']);
        $this->applyScope($purgeBuilder, $this->scopeFor($resolved['resource']->resource_key, 'purge'), $resolved['schema']);
        $affected = $purgeBuilder->delete();
        if ($affected === 0) {
            throw $this->error('record_not_found', 'record not found');
        }
        return ['success' => true, 'affected' => $affected];
    }

    /** @param array<string, mixed>|object $input */
    public function executeAction(array|object $input): array
    {
        $input = Payload::dto($input, ExecuteActionInput::class);
        $resolved = $this->resolved((string) ($input['resource'] ?? ''));
        $action = trim((string) ($input['action'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, $action);
        if (!in_array($action, ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge'], true) && !in_array($action, array_keys((array) $resolved['schema']->actions), true)) {
            throw $this->error('action_not_found', 'action not found', ['action' => $action]);
        }
        $capabilityActions = ['import' => 'allow_import', 'export' => 'allow_export', 'duplicate' => 'allow_duplicate', 'print' => 'allow_print', 'restore' => 'allow_recycle', 'purge' => 'allow_recycle'];
        if (isset($capabilityActions[$action]) && empty($resolved['schema']->capabilities[$capabilityActions[$action]])) {
            throw $this->error('action_not_available', 'action is not available', ['action' => $action]);
        }
        $actions = (array) ($resolved['schema']->actions ?? []);
        if (isset($actions[$action]) && array_key_exists('enabled', (array) $actions[$action]) && !$actions[$action]['enabled']) {
            throw $this->error('action_not_available', 'action is not available', ['action' => $action]);
        }
        if (in_array($action, ['delete', 'restore', 'purge'], true)) {
            $method = $action;
            $result = $this->{$method}(['resource' => $input['resource'], 'id' => $input['id'] ?? 0]);
            return $result + ['message' => 'action executed'];
        }
        $recordId = (int) ($input['id'] ?? 0);
        if ($recordId > 0) {
            $record = DB::table($resolved['resource']->table_name)->where('id', $recordId);
            $this->applyRecycle($record, $resolved['resource'], '');
            $this->applyScope($record, $this->scopeFor($resolved['resource']->resource_key, $action), $resolved['schema']);
            if (!$record->exists()) {
                throw $this->error('record_not_found', 'record not found', ['id' => $recordId]);
            }
        }
        $hook = $this->hooks?->get($resolved['resource']->resource_key);
        if ($hook) {
            return $hook->executeAction($resolved['resource']->resource_key, $action, $input, $resolved['schema']);
        }
        if ($this->executor && method_exists($this->executor, 'executeAction')) {
            return (array) $this->executor->executeAction($input);
        }
        throw $this->error('action_not_available', 'action executor is not configured', ['action' => $action]);
    }

    /** @param array<string, mixed>|object $query */
    public function relationCandidates(array|object $query): array
    {
        $query = Payload::dto($query, RelationCandidatesQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'view');
        $field = null;
        foreach ((array) $resolved['schema']->fields as $candidate) {
            if (($candidate['name'] ?? '') === ($query['field'] ?? '')) {
                $field = $candidate;
            }
        }
        if (!$field) {
            throw $this->error('invalid_input', 'relation field not found', ['field' => $query['field'] ?? '']);
        }
        $options = (array) ($field['options'] ?? []);
        $reference = (array) ($field['reference'] ?? ($options['reference'] ?? []));
        if ($reference === [] && strtolower((string) ($options['mode'] ?? '')) === 'static') {
            $items = array_map(static fn (array $item): array => ['value' => $item['value'] ?? null, 'label' => (string) ($item['label'] ?? ''), 'disabled' => (bool) ($item['disabled'] ?? false)], (array) ($options['items'] ?? []));
            $keyword = strtolower(trim((string) ($query['keyword'] ?? '')));
            if ($keyword !== '') {
                $items = array_values(array_filter($items, static fn (array $item): bool => str_contains(strtolower($item['label']), $keyword) || str_contains(strtolower((string) $item['value']), $keyword)));
            }
            $total = count($items);
            $page = max(1, (int) ($query['page'] ?? 1));
            $limit = max(1, min(200, (int) ($query['limit'] ?? 20)));
            return ['results' => array_slice($items, ($page - 1) * $limit, $limit), 'selected' => [], 'total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $total > $page * $limit];
        }
        if ($reference === []) {
            throw $this->error('invalid_input', 'field is not relation', ['field' => $query['field'] ?? '']);
        }
        $target = $this->resolved((string) ($reference['resource'] ?? ''));
        $this->authorize($target['resource']->resource_key, 'view');
        $builder = DB::table($target['resource']->table_name);
        $targetScope = $this->scopeFor($target['resource']->resource_key, 'view');
        $this->applyScope($builder, $targetScope, $target['schema']);
        $valueField = (string) ($reference['value_field'] ?? 'id');
        $labelField = (string) ($reference['label_field'] ?? $valueField);
        $keyword = trim((string) ($query['keyword'] ?? ''));
        if ($keyword !== '') {
            $builder->where(function ($nested) use ($labelField, $valueField, $keyword): void {
                $nested->where($labelField, 'like', '%'.$keyword.'%')->orWhere($valueField, 'like', '%'.$keyword.'%');
            });
        }
        $filters = (array) (($reference['filters'] ?? $options['filters'] ?? []));
        foreach ($filters as $filter) {
            $filter = (array) $filter;
            $from = (string) ($filter['from'] ?? '');
            $value = array_key_exists('value', $filter) ? $filter['value'] : null;
            if ($from !== '') {
                $source = str_starts_with($from, 'form.') ? (array) ($query['form_values'] ?? []) : (array) ($query['params'] ?? []);
                foreach (explode('.', preg_replace('/^(form|params)\./', '', $from) ?? '') as $part) {
                    $source = is_array($source) ? ($source[$part] ?? null) : null;
                }
                $value = $source;
            }
            if ($value !== null && isset($filter['field'])) {
                $builder->where($filter['field'], $filter['operator'] ?? 'eq', $value);
            }
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(200, (int) ($query['limit'] ?? ($reference['limit'] ?? 20))));
        $total = (clone $builder)->count();
        $rows = $builder->orderBy($labelField)->forPage($page, $limit)->get();
        $results = $rows->map(static fn ($row): array => ['value' => $row->{$valueField}, 'label' => (string) ($row->{$labelField} ?? ''), 'parent_value' => ($reference['parent_field'] ?? null) ? ($row->{$reference['parent_field']} ?? null) : null, 'description' => ($reference['description_field'] ?? null) ? (string) ($row->{$reference['description_field']} ?? '') : '', 'disabled' => ($reference['disabled_field'] ?? null) ? (bool) ($row->{$reference['disabled_field']} ?? false) : false])->all();
        $selected = [];
        foreach ((array) ($query['values'] ?? []) as $selectedValue) {
            $selectedBuilder = DB::table($target['resource']->table_name)->where($valueField, $selectedValue);
            $this->applyScope($selectedBuilder, $targetScope, $target['schema']);
            $selectedRow = $selectedBuilder->first();
            if ($selectedRow) {
                $selected[] = ['value' => $selectedRow->{$valueField}, 'label' => (string) ($selectedRow->{$labelField} ?? '')];
            }
        }
        return ['results' => $results, 'selected' => $selected, 'total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $total > $page * $limit];
    }

    /** @param array<string, mixed>|object $query */
    public function schemaSnapshot(array|object $query): array
    {
        $query = Payload::dto($query, SchemaSnapshotQuery::class);
        $resolved = $this->resolved((string) ($query['resource'] ?? ''));
        $this->authorize($resolved['resource']->resource_key, 'view');
        $schema = $resolved['schema'];
        return ['resource' => $resolved['resource']->resource_key, 'version' => ['version_id' => (int) $resolved['version']->id, 'version' => (int) $resolved['version']->version, 'status' => $resolved['version']->status, 'checksum' => $resolved['version']->checksum], 'features' => $schema->features ?? [], 'capabilities' => $schema->capabilities ?? [], 'state' => $schema->state ?? null, 'tree' => $schema->tree ?? null, 'fields' => $schema->fields ?? [], 'actions' => $schema->actions ?? []];
    }

    /** @return array{resource:Resource,version:object,schema:Schema} */
    /**
     * 解析资源及活动版本，运行时只读取 active 版本，防止草稿内容提前对业务数据生效。
     *
     * @return array{resource:Resource,schema:Schema}
     */
    private function resolved(string $key): array
    {
        $resource = Resource::query()->where('resource_key', trim($key))->first();
        if (!$resource) {
            throw $this->error('draft_not_found', 'resource not found', ['resource_key' => $key]);
        }
        if ($resource->status === 'disabled') {
            throw $this->error('resource_disabled', 'resource is disabled', ['resource_key' => $key]);
        }
        $version = $resource->versions()->where('status', 'active')->first();
        if (!$version) {
            throw $this->error('active_version_not_found', 'active version not found', ['resource_key' => $key]);
        }
        return ['resource' => $resource, 'version' => $version, 'schema' => new Schema((array) $version->schema_json)];
    }

    private function authorize(string $resourceKey, string $action): void
    {
        if (!$this->authorization || !$this->subjectContext) {
            throw $this->error('action_not_available', 'schema authorization is not configured', [
                'resource_key' => $resourceKey,
                'action' => $action,
            ]);
        }

        $subject = $this->subjectContext->get();
        if (!$this->authorization->allows($subject, $resourceKey, $action)) {
            throw $this->error('forbidden', 'schema action is not allowed', [
                'resource_key' => $resourceKey,
                'action' => $action,
            ]);
        }
    }

    private function scopeFor(string $resourceKey, string $action): ?\PTAdmin\Schema\Contract\ServerConstraint
    {
        if (!$this->scopeResolver || !$this->subjectContext) {
            return null;
        }

        return $this->scopeResolver->resolve($this->subjectContext->get(), $resourceKey, $action);
    }

    /** @param array<string, mixed> $data */
    /**
     * 按字段定义处理输入顺序：默认值/生成器、归一化、类型转换和校验，最后只保留可持久化字段。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareInput(array $data, Schema $schema, bool $create, string $mode, ?Resource $resource = null, int $currentId = 0): array
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            $mode = 'submit';
        }
        if (!in_array($mode, ['draft', 'submit'], true)) {
            throw $this->error('invalid_input', 'write mode is not supported');
        }
        $fields = [];
        foreach ((array) $schema->fields as $field) {
            $fields[$field['name']] = $field;
        }
        // Keep track of caller-provided values so system defaults can pass through
        // readonly filtering while explicit readonly overrides remain ignored.
        $inputFields = array_fill_keys(array_keys($data), true);
        if ($mode === 'draft') {
            $this->applyDraftState($data, $fields, $schema);
        }
        if ($create && $mode === 'submit') {
            $this->applySubmitStateDefault($data, $schema);
        }
        if ($create) {
            foreach ($fields as $name => $field) {
                if (!array_key_exists($name, $data) && array_key_exists('default_expr', $field)) {
                    $data[$name] = $this->evaluateDefault((array) $field['default_expr'], (string) ($field['type'] ?? 'string'));
                }
                if (!array_key_exists($name, $data) && array_key_exists('default', $field)) {
                    $data[$name] = $field['default'];
                }
            }
        }
        foreach ($data as $name => $value) {
            if (!isset($fields[$name])) {
                // Runtime input is a schema whitelist. Undeclared fields are
                // dropped before validation and persistence, matching the Go
                // input pipeline; malformed protocol DTO fields still fail
                // during DTO construction at the boundary.
                unset($data[$name]);
                continue;
            }
            $field = $fields[$name];
            $generator = (array) (($field['extensions'] ?? [])['generator'] ?? []);
            $protected = !empty($field['readonly']) || isset(($field['extensions'] ?? [])['mirror']) || isset(($field['extensions'] ?? [])['clone']);
            if ($protected && isset($inputFields[$name])) {
                $value = $this->normalizeValue($value, (array) ($field['normalize'] ?? []));
                $value = $this->castValue($value, $field);
                $this->validateValue($value, $field, $mode);
                $this->validateOptions($value, $field);
                if (array_key_exists('default', $field)) {
                    $data[$name] = $field['default'];
                } else {
                    unset($data[$name]);
                }
                continue;
            }
            if (!$protected && !empty($generator) && empty($generator['allow_override']) && isset($inputFields[$name])) {
                unset($data[$name]);
                continue;
            }
            $value = $this->normalizeValue($value, (array) ($field['normalize'] ?? []));
            $data[$name] = $this->castValue($value, $field);
            $this->validateValue($data[$name], $field, $mode);
            $this->validateOptions($data[$name], $field);
        }
        if ($create) {
            foreach ($fields as $name => $field) {
                if ($mode !== 'draft' && !empty($field['required']) && empty($field['readonly']) && (!array_key_exists($name, $data) || $data[$name] === null || $data[$name] === '')) {
                    throw $this->error('invalid_input', 'required field is missing: '.$name, ['field' => $name]);
                }
            }
        }
        $this->applyGenerators($data, $fields, $resource, $create, $currentId);
        $this->applyCloneFields($data, $fields, $resource);
        return $this->encodeForStorage($data, $fields);
    }

    private function applyDraftState(array &$data, array $fields, Schema $schema): void
    {
        if (empty($schema->capabilities['allow_draft'])) {
            return;
        }
        $state = (array) ($schema->state ?? []);
        $fieldName = trim((string) ($state['field'] ?? ''));
        $draftValue = null;
        $found = false;
        foreach ((array) ($state['values'] ?? []) as $value => $definition) {
            if (strtolower((string) ($definition['kind'] ?? '')) === 'draft') {
                $draftValue = $value;
                $found = true;
                break;
            }
        }
        if ($fieldName !== '' && $found && isset($fields[$fieldName]) && !empty($fields[$fieldName]['persisted'])) {
            $data[$fieldName] = is_numeric($draftValue) ? (int) $draftValue : $draftValue;
        }
    }

    private function applySubmitStateDefault(array &$data, Schema $schema): void
    {
        $state = (array) ($schema->state ?? []);
        $field = trim((string) ($state['field'] ?? ''));
        if ($field !== '' && !array_key_exists($field, $data) && array_key_exists('default_value', $state)) {
            $data[$field] = $state['default_value'];
        }
    }

    /**
     * 执行 sequence/slug 生成器；更新场景保留已存在值，避免无意中改变稳定业务编码。
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $fields
     */
    private function applyGenerators(array &$data, array $fields, ?Resource $resource, bool $create, int $currentId): void
    {
        foreach ($fields as $name => $field) {
            $generator = (array) (($field['extensions'] ?? [])['generator'] ?? []);
            if ($generator === []) {
                continue;
            }
            $on = strtolower((string) ($generator['on'] ?? 'create'));
            if (!$create && !in_array($on, ['update', 'always'], true)) {
                continue;
            }
            if ($create && !in_array($on, ['create', 'always'], true)) {
                continue;
            }
            if (!empty($generator['allow_override']) && array_key_exists($name, $data) && $data[$name] !== null && $data[$name] !== '') {
                continue;
            }
            $type = strtolower((string) ($generator['type'] ?? ''));
            if ($type === 'slug') {
                $source = (string) ($data[$generator['source_field'] ?? ''] ?? '');
                if ($source === '') {
                    continue;
                }
                $value = $this->slugify($source, (array) ($generator['slug'] ?? []));
                if (($generator['unique'] ?? true) && $resource) {
                    $base = $value;
                    for ($n = 2; DB::table($resource->table_name)->where($name, $value)->when($currentId > 0, fn ($q) => $q->where('id', '!=', $currentId))->exists(); $n++) {
                        $value = $base.'-'.$n;
                    }
                }
                $data[$name] = $value;
                continue;
            }
            if ($type === 'sequence') {
                $pattern = (string) ($generator['pattern'] ?? '');
                if (!preg_match('/\{seq(?::(\d+))?\}/', $pattern, $match)) {
                    continue;
                }
                $rendered = str_replace(['{yyyy}', '{yy}', '{MM}', '{dd}'], [date('Y'), date('y'), date('m'), date('d')], $pattern);
                $pad = (int) ($match[1] ?? ($generator['sequence']['pad'] ?? 0));
                $next = (int) ($generator['sequence']['start'] ?? 1);
                do {
                    $token = $pad > 0 ? str_pad((string) $next, $pad, '0', STR_PAD_LEFT) : (string) $next;
                    $candidate = str_replace($match[0], $token, $rendered);
                    $next++;
                } while ($resource && DB::table($resource->table_name)->where($name, $candidate)->when($currentId > 0, fn ($q) => $q->where('id', '!=', $currentId))->exists() && $next < 1000000);
                $data[$name] = $candidate;
            }
        }
    }

    private function slugify(string $value, array $config): string
    {
        $separator = (string) ($config['separator'] ?? '-');
        $value = trim($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = preg_replace('/[^A-Za-z0-9]+/', $separator, $value) ?? '';
        $value = trim($value, $separator);
        return !empty($config['lowercase']) || !array_key_exists('lowercase', $config) ? strtolower($value) : $value;
    }

    private function applyCloneFields(array &$data, array $fields, ?Resource $resource): void
    {
        foreach ($fields as $name => $field) {
            $clone = (array) (($field['extensions'] ?? [])['clone'] ?? []);
            if ($clone === [] || !$resource) {
                continue;
            }
            $sourceId = $data[$clone['from_field'] ?? ''] ?? null;
            if ($sourceId === null || array_key_exists($name, $data)) {
                continue;
            }
            $reference = (array) ($field['reference'] ?? []);
            $target = Resource::query()->where('resource_key', $reference['resource'] ?? '')->first();
            if (!$target) {
                continue;
            }
            $row = DB::table($target->table_name)->where($reference['value_field'] ?? 'id', $sourceId)->first();
            if ($row && isset($clone['source_field'])) {
                $data[$name] = $row->{$clone['source_field']} ?? null;
            }
        }
    }

    private function evaluateDefault(array $expression, string $fieldType): mixed
    {
        $type = strtolower(trim((string) ($expression['type'] ?? '')));
        if ($type === 'literal') {
            return $expression['value'] ?? null;
        }
        $timezone = trim((string) ($expression['timezone'] ?? ''));
        $date = now($timezone !== '' ? $timezone : null);
        if ($type === 'today' || $type === 'start_of_day') {
            $date = $date->startOfDay();
        } elseif ($type === 'end_of_day') {
            $date = $date->endOfDay();
        } elseif ($type === 'start_of_month') {
            $date = $date->startOfMonth();
        } elseif ($type === 'end_of_month') {
            $date = $date->endOfMonth();
        }
        $offset = (array) ($expression['offset'] ?? []);
        if ($offset !== []) {
            $date = $date->addYears((int) ($offset['years'] ?? 0))->addMonths((int) ($offset['months'] ?? 0))->addWeeks((int) ($offset['weeks'] ?? 0))->addDays((int) ($offset['days'] ?? 0))->addHours((int) ($offset['hours'] ?? 0))->addMinutes((int) ($offset['minutes'] ?? 0))->addSeconds((int) ($offset['seconds'] ?? 0));
        }
        $format = strtolower(trim((string) ($expression['format'] ?? '')));
        if ($format === 'timestamp') {
            return $date->valueOf();
        }
        if ($format === 'unix_seconds') {
            return $date->timestamp;
        }
        if ($format === 'date' || $fieldType === 'date') {
            return $date->format('Y-m-d');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function normalizeValue(mixed $value, array $normalize): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (!empty($normalize['trim'])) {
            $value = trim($value);
        }
        if (!empty($normalize['empty_as_null']) && $value === '') {
            return null;
        }
        $case = strtolower((string) ($normalize['case'] ?? ''));
        if ($case === 'lower') {
            $value = strtolower($value);
        }
        if ($case === 'upper') {
            $value = strtoupper($value);
        }
        return $value;
    }

    /**
     * 应用 required、长度、范围、格式和复杂度规则；draft 模式允许协议规定的暂存态空值。
     *
     * @param array<string, mixed> $field
     */
    private function validateValue(mixed $value, array $field, string $mode): void
    {
        if ($mode === 'draft' || $value === null || $value === '') {
            return;
        }
        foreach ((array) ($field['validation'] ?? []) as $rule) {
            $type = strtolower((string) ($rule['type'] ?? ''));
            $ruleValue = $rule['value'] ?? null;
            $message = (string) ($rule['message'] ?? ('field '.$field['name'].' is invalid'));
            if ($type === 'max_length' && mb_strlen((string) $value) > (int) $ruleValue) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'min_length' && mb_strlen((string) $value) < (int) $ruleValue) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'min' && (float) $value < (float) $ruleValue) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'max' && (float) $value > (float) $ruleValue) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'pattern' && @preg_match('~'.(string) $ruleValue.'~u', (string) $value) !== 1) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'format' && !$this->validFormat((string) $value, (string) $ruleValue)) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'complexity' && !$this->validComplexity((string) $value, (array) ($rule['params'] ?? []))) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
            if ($type === 'range' && !$this->validRange($value, (array) ($rule['params'] ?? []))) {
                throw $this->error('invalid_input', $message, ['field' => $field['name']]);
            }
        }
    }

    private function validComplexity(string $value, array $params): bool
    {
        $length = mb_strlen($value);
        return (!isset($params['min_length']) || $length >= (int) $params['min_length'])
            && (!isset($params['max_length']) || $length <= (int) $params['max_length'])
            && (empty($params['require_lower']) || preg_match('/[a-z]/u', $value) === 1)
            && (empty($params['require_upper']) || preg_match('/[A-Z]/u', $value) === 1)
            && (empty($params['require_digit']) || preg_match('/[0-9]/u', $value) === 1)
            && (empty($params['require_symbol']) || preg_match('/[^\pL\pN]/u', $value) === 1);
    }

    private function validRange(mixed $value, array $params): bool
    {
        if (!is_array($value) || count($value) !== 2 || $value[0] === null || $value[0] === '' || $value[1] === null || $value[1] === '') {
            return false;
        }
        $unit = (string) ($params['span_unit'] ?? 'day');
        $parse = static function (mixed $endpoint) use ($unit): int|false {
            if ($unit === 'minute' && is_string($endpoint) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $endpoint) === 1) {
                [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $endpoint)), 3, 0);
                return $hour <= 23 && $minute <= 59 && $second <= 59 ? $hour * 3600 + $minute * 60 + $second : false;
            }
            if ($unit === 'day' && is_string($endpoint) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endpoint) === 1) {
                $time = strtotime($endpoint.' 00:00:00');
                return $time === false ? false : $time;
            }
            return false;
        };
        $start = $parse($value[0]);
        $end = $parse($value[1]);
        if ($start === false || $end === false || (($params['ordered'] ?? true) && $start > $end)) {
            return false;
        }
        $spanSeconds = $unit === 'minute' ? 60 : 86400;
        return !isset($params['max_span']) || $end < $start || $end - $start <= (int) $params['max_span'] * $spanSeconds;
    }

    private function validateOptions(mixed $value, array $field): void
    {
        $options = (array) ($field['options'] ?? []);
        if (strtolower((string) ($options['mode'] ?? '')) !== 'static' || strtolower((string) ($options['value_policy'] ?? 'open')) !== 'closed') {
            return;
        }
        $allowed = [];
        foreach ((array) ($options['items'] ?? []) as $item) {
            $allowed[] = $item['value'] ?? null;
        }
        $values = !empty($field['multiple']) && is_array($value) ? $value : [$value];
        foreach ($values as $candidate) {
            $ok = false;
            foreach ($allowed as $item) {
                if ((string) $item === (string) $candidate) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw $this->error('invalid_input', 'field option is not allowed', ['field' => $field['name'], 'value' => $candidate]);
            }
        }
    }

    private function validFormat(string $value, string $format): bool
    {
        return match (strtolower($format)) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false, 'url' => filter_var($value, FILTER_VALIDATE_URL) !== false, 'uuid' => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value), 'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false, 'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false, 'cn_mobile' => (bool) preg_match('/^1[3-9]\d{9}$/', $value), 'lower_alpha' => (bool) preg_match('/^[a-z]+$/', $value), 'alpha' => (bool) preg_match('/^[a-z]+$/i', $value), 'alpha_num' => (bool) preg_match('/^[a-z0-9]+$/i', $value), 'slug' => (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value), 'identifier' => (bool) preg_match('/^[a-z][a-z0-9_]*$/', $value), default => true
        };
    }

    private function castValue(mixed $value, array $field): mixed
    {
        if (!empty($field['multiple'])) {
            if (!is_array($value)) {
                throw $this->error('invalid_input', 'multiple field requires array value', ['field' => $field['name']]);
            }
            return array_map(fn ($item): mixed => $this->castScalar($item, (string) $field['type']), $value);
        }
        $value = $this->castScalar($value, (string) ($field['type'] ?? 'string'));
        $secret = (array) (($field['extensions'] ?? [])['secret'] ?? []);
        if (in_array(strtolower((string) ($secret['storage_mode'] ?? '')), ['hash', 'hashed'], true) && $value !== null) {
            $value = hash('sha256', (string) $value);
        }
        return $value;
    }
    private function castScalar(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value, 'number' => (float) $value, 'boolean' => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true), 'date', 'datetime' => is_numeric($value) ? (int) $value : (int) strtotime((string) $value), 'json' => $value, default => is_string($value) ? $value : (string) $value
        };
    }
    private function encodeForStorage(array $data, array $fields): array
    {
        foreach ($data as $key => $value) {
            if (!isset($fields[$key]) || empty($fields[$key]['persisted'])) {
                unset($data[$key]);
                continue;
            }
            if (!empty($fields[$key]['multiple']) || ($fields[$key]['type'] ?? '') === 'json') {
                $data[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $data;
    }
    private function output(array $row, Schema $schema, string $resourceKey = ''): array
    {
        foreach ((array) $schema->fields as $field) {
            $name = $field['name'];
            if (array_key_exists($name, $row) && (!empty($field['multiple']) || ($field['type'] ?? '') === 'json') && is_string($row[$name])) {
                $row[$name] = json_decode($row[$name], true);
            }
            if (array_key_exists($name, $row) && $row[$name] !== null && empty($field['multiple'])) {
                $row[$name] = match ((string) ($field['type'] ?? '')) {
                    'boolean' => (bool) $row[$name],
                    'integer' => (int) $row[$name],
                    'number' => (float) $row[$name],
                    default => $row[$name],
                };
            }
            $extensions = (array) ($field['extensions'] ?? []);
            $secret = (array) ($extensions['secret'] ?? []);
            $storageMode = strtolower((string) ($secret['storage_mode'] ?? ''));
            if (array_key_exists($name, $row) && (!empty($secret) && (empty($secret['readable']) || in_array($storageMode, ['hash', 'hashed'], true)))) {
                unset($row[$name]);
            }
            if (array_key_exists($name, $row) && isset($extensions['mask'])) {
                $row[$name] = $this->maskValue($row[$name], (array) $extensions['mask']);
            }
            if (isset($field['reference'], $row[$name]) && $row[$name] !== null) {
                $reference = (array) $field['reference'];
                $target = Resource::query()->where('resource_key', $reference['resource'] ?? '')->first();
                if ($target) {
                    $value = $row[$name];
                    if (!empty($field['multiple']) && is_array($value)) {
                        $labels = [];
                        foreach ($value as $item) {
                            $targetRow = DB::table($target->table_name)->where($reference['value_field'] ?? 'id', $item)->first();
                            if ($targetRow) {
                                $labels[] = (string) ($targetRow->{$reference['label_field'] ?? 'id'} ?? '');
                            }
                        }
                        if ($labels !== []) {
                            $row['_'.$name.'_label'] = $labels;
                        }
                    } else {
                        $targetRow = DB::table($target->table_name)->where($reference['value_field'] ?? 'id', $value)->first();
                        if ($targetRow) {
                            $row['_'.$name.'_label'] = $targetRow->{$reference['label_field'] ?? 'id'} ?? null;
                        }
                    }
                }
            }
            $this->applyMirrorOutput($row, $field);
        }
        $hook = $resourceKey !== '' ? $this->hooks?->get($resourceKey) : null;

        return $hook ? $hook->output($resourceKey, $row, $schema) : $row;
    }

    /**
     * 按 mask 配置裁剪敏感字段输出，确保列表、详情和关联候选不会绕过脱敏规则。
     *
     * @param array<string, mixed> $mask
     */
    private function maskValue(mixed $value, array $mask): mixed
    {
        if ($value === null) {
            return null;
        }
        $raw = (string) $value;
        $type = strtolower((string) ($mask['type'] ?? ''));
        if ($type === 'custom') {
            return $mask['pattern'] ?? '****';
        }
        if ($type === 'email') {
            $parts = explode('@', $raw, 2);
            return count($parts) === 2 ? substr($parts[0], 0, 1).str_repeat('*', max(1, strlen($parts[0]) - 1)).'@'.$parts[1] : '****';
        }
        if ($type === 'name') {
            return $raw === '' ? $raw : mb_substr($raw, 0, 1).str_repeat('*', max(0, mb_strlen($raw) - 1));
        }
        $keepLeft = $type === 'phone' || $type === 'id_card' ? 3 : 1;
        $keepRight = $type === 'phone' || $type === 'id_card' ? 4 : 1;
        $length = mb_strlen($raw);
        return $length <= $keepLeft + $keepRight ? str_repeat('*', $length) : mb_substr($raw, 0, $keepLeft).str_repeat('*', $length - $keepLeft - $keepRight).mb_substr($raw, -$keepRight);
    }

    private function applyMirrorOutput(array &$row, array $field): void
    {
        $mirror = (array) (($field['extensions'] ?? [])['mirror'] ?? []);
        if ($mirror === []) {
            return;
        }
        $from = (string) ($mirror['from_field'] ?? '');
        $source = (string) ($mirror['source_field'] ?? '');
        if ($from === '' || $source === '' || !array_key_exists($from, $row)) {
            return;
        }
        $reference = (array) ($field['reference'] ?? []);
        $target = Resource::query()->where('resource_key', $reference['resource'] ?? '')->first();
        if (!$target) {
            return;
        }
        $related = DB::table($target->table_name)->where($reference['value_field'] ?? 'id', $row[$from])->first();
        if ($related) {
            $row[$field['name']] = $related->{$source} ?? null;
        }
    }
    private function applyFilters($builder, array $filters, Schema $schema): void
    {
        $fields = [];
        foreach ((array) $schema->fields as $field) {
            $fields[$field['name']] = $field;
        }
        foreach ($filters as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $op = strtolower((string) ($filter['operator'] ?? 'eq'));
            if (!isset($fields[$field]) || empty($fields[$field]['persisted'])) {
                continue;
            }
            $value = $filter['value'] ?? null;
            if ($op === 'in' && is_array($value)) {
                $builder->whereIn($field, $value);
            } elseif ($op === 'contains') {
                $builder->where($field, 'like', '%'.$value.'%');
            } elseif ($op === 'neq') {
                $builder->where($field, '!=', $value);
            } elseif (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
                $builder->where($field, ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op], $value);
            } else {
                $builder->where($field, '=', $value);
            }
        }
    }
    private function applySorts($builder, array $sorts, Schema $schema): void
    {
        foreach ($sorts as $sort) {
            $field = (string) ($sort['field'] ?? '');
            if ($field === 'id' || collect((array) $schema->fields)->contains(fn ($item): bool => ($item['name'] ?? '') === $field && !empty($item['sortable']))) {
                $builder->orderBy($field, strtolower((string) ($sort['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc');
            }
        }
    }
    private function applyRecycle($builder, Resource $resource, string $mode): void
    {
        if (!DB::getSchemaBuilder()->hasColumn($resource->table_name, 'deleted_at')) {
            return;
        }
        $mode = strtolower(trim($mode));
        if ($mode === 'only') {
            $builder->whereNotNull('deleted_at');
        } elseif ($mode !== 'with') {
            $builder->whereNull('deleted_at');
        }
    }
    private function applyStateKinds($builder, mixed $kinds, Schema $schema): void
    {
        $kinds = array_values(array_filter((array) $kinds, static fn ($value): bool => trim((string) $value) !== ''));
        if ($kinds === []) {
            return;
        }
        $state = (array) ($schema->state ?? []);
        $field = trim((string) ($state['field'] ?? ''));
        if ($field === '') {
            throw $this->error('invalid_input', 'state kinds require state config');
        }
        $values = [];
        foreach ((array) ($state['values'] ?? []) as $value => $definition) {
            if (in_array((string) ($definition['kind'] ?? ''), array_map('strval', $kinds), true)) {
                $values[] = is_numeric($value) ? (int) $value : $value;
            }
        }
        if ($values === []) {
            $builder->whereRaw('1 = 0');
            return;
        }
        $builder->whereIn($field, $values);
    }
    /**
     * 将服务端约束转换为白名单查询条件；约束字段必须存在于 Schema，避免客户端注入任意列名。
     *
     * @param mixed $builder
     */
    private function applyScope($builder, mixed $constraint, ?Schema $schema = null): void
    {
        $this->constraintApplier->apply($builder, $constraint, $schema);
    }
    /** 将错误统一标记为 runtime 阶段，供宿主按协议响应和审计。 */
    private function error(string $code, string $message, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details, [], false, 'runtime');
    }
}
