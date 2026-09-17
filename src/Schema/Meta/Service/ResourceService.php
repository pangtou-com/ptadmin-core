<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Service;

use Illuminate\Support\Facades\DB;
use PTAdmin\Schema\Contract\BeginDraftEditResult;
use PTAdmin\Schema\Contract\CreateResourceInput;
use PTAdmin\Schema\Contract\CreateResourceResult;
use PTAdmin\Schema\Contract\GetActiveVersionResult;
use PTAdmin\Schema\Contract\GetDraftSchemaResult;
use PTAdmin\Schema\Contract\GetResourceResult;
use PTAdmin\Schema\Contract\GetVersionResult;
use PTAdmin\Schema\Contract\ListResourcesResult;
use PTAdmin\Schema\Contract\ListResourcesQuery;
use PTAdmin\Schema\Contract\ListVersionsQuery;
use PTAdmin\Schema\Contract\ListVersionsResult;
use PTAdmin\Schema\Contract\GetVersionQuery;
use PTAdmin\Schema\Contract\ResourceInfo;
use PTAdmin\Schema\Contract\SaveDraftSchemaResult;
use PTAdmin\Schema\Contract\SaveDraftSchemaInput;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contract\SyncCodeResourceResult;
use PTAdmin\Schema\Contract\SyncCodeResourceInput;
use PTAdmin\Schema\Contract\VersionInfo;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\ResourceVersion;
use PTAdmin\Schema\Support\Payload;
use PTAdmin\Schema\Support\Protocol;

/**
 * 管理资源元数据、草稿和版本生命周期；写入操作通过事务保证资源与首个版本同步落库。
 */
final class ResourceService implements \PTAdmin\Schema\Contracts\ResourceServiceInterface
{
    public function __construct(private ResourceLocator $locator)
    {
    }

    /** @param array<string, mixed>|object $input */
    public function createResource(array|object $input): CreateResourceResult
    {
        $input = Payload::dto($input, CreateResourceInput::class);
        $namespace = trim((string) ($input['namespace'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw $this->error('invalid_input', 'name is required', 'resource');
        }
        if ($namespace !== '' && !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $namespace)) {
            throw $this->error('invalid_input', 'namespace is invalid', 'resource', ['namespace' => $namespace]);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/', $name)) {
            throw $this->error('invalid_input', 'name is invalid', 'resource', ['name' => $name]);
        }
        $key = $namespace === '' ? $name : $namespace.'.'.$name;
        $table = trim((string) ($input['table_name'] ?? ''));
        $table = $table !== '' ? $table : ($namespace === '' ? $name : $namespace.'_'.$name);
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $table)) {
            throw $this->error('invalid_input', 'table_name is invalid', 'resource', ['table_name' => $table]);
        }
        $existing = Resource::query()->where('resource_key', $key)->first();
        if ($existing) {
            if (($sourceType = trim((string) ($input['source_type'] ?? ''))) !== '' && $existing->source_type !== '' && $existing->source_type !== $sourceType) {
                throw $this->error('source_type_conflict', 'resource source type conflicts', 'resource', ['resource_key' => $key, 'source_type' => $sourceType]);
            }
            throw $this->error('resource_key_conflict', 'resource key already exists', 'resource', ['resource_key' => $key]);
        }
        $schema = new Schema([
            'title' => (string) ($input['title'] ?? ''),
            'name' => $name,
            'description' => (string) ($input['description'] ?? ''),
        ]);
        $this->assertValid($schema, 'draft');
        $now = now();
        [$resource, $version] = DB::transaction(static function () use ($input, $namespace, $name, $key, $table, $schema, $now): array {
            $resource = Resource::query()->create([
                'namespace' => $namespace,
                'name' => $name,
                'resource_key' => $key,
                'table_name' => $table,
                'migration_owner' => in_array(($input['migration_owner'] ?? 'schema'), ['schema', 'domain'], true) ? ($input['migration_owner'] ?? 'schema') : 'schema',
                'title' => (string) ($input['title'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'source_type' => trim((string) ($input['source_type'] ?? '')),
                'owner_type' => trim((string) ($input['owner_type'] ?? '')),
                'owner_code' => trim((string) ($input['owner_code'] ?? '')),
                'design_flow_id' => trim((string) ($input['design_flow_id'] ?? '')),
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $version = ResourceVersion::query()->create([
                'resource_id' => $resource->id,
                'version' => 1,
                'status' => 'draft',
                'schema_json' => $schema->toArray(),
                'checksum' => $schema->checksum(),
                'check_status' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [$resource, $version];
        });
        return new CreateResourceResult(['resource' => $this->resourceInfo($resource), 'draft_version' => $this->locator->versionInfo($version)]);
    }

    /** @param array<string, mixed>|object $query */
    public function listResources(array|object $query = []): ListResourcesResult
    {
        $query = Payload::dto($query, ListResourcesQuery::class);
        $builder = Resource::query()->orderByDesc('id');
        foreach (['namespace', 'source_type', 'owner_type', 'owner_code', 'status'] as $field) {
            if (($value = trim((string) ($query[$field] ?? ''))) !== '') {
                $builder->where($field, $value);
            }
        }
        if (($keyword = trim((string) ($query['keyword'] ?? ''))) !== '') {
            $builder->where(static function ($nested) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $nested->where('resource_key', 'like', $like)->orWhere('name', 'like', $like)->orWhere('title', 'like', $like)->orWhere('table_name', 'like', $like);
            });
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $total = (clone $builder)->count();
        $items = $builder->forPage($page, $limit)->get()->map(fn (Resource $resource): ResourceInfo => $this->resourceInfo($resource))->all();
        return new ListResourcesResult(['results' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function getResource(string $resourceKey): GetResourceResult
    {
        $resource = $this->locator->resource($resourceKey);
        return new GetResourceResult(['resource' => $this->resourceInfo($resource)]);
    }

    public function getDraftSchema(string $resourceKey): GetDraftSchemaResult
    {
        $resource = $this->locator->resource($resourceKey);
        $version = $this->locator->draft($resource);
        return new GetDraftSchemaResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($version), 'schema' => new Schema($version->schema_json)]);
    }

    public function beginDraftEdit(string $resourceKey): BeginDraftEditResult
    {
        $resource = $this->locator->resource($resourceKey);
        if ($resource->status === 'archived') {
            throw $this->error('resource_archived', 'resource is archived', 'resource', ['resource_key' => $resourceKey]);
        }
        $version = $resource->versions()->where('status', 'draft')->first();
        $created = false;
        if (!$version) {
            $active = $resource->versions()->where('status', 'active')->first();
            if (!$active) {
                throw $this->error('active_version_not_found', 'active version not found', 'draft', ['resource_key' => $resourceKey]);
            }
            $version = ResourceVersion::query()->create([
                'resource_id' => $resource->id, 'version' => $active->version + 1, 'status' => 'draft',
                'schema_json' => $active->schema_json, 'checksum' => $active->checksum, 'check_status' => 'none',
            ]);
            $created = true;
        }
        return new BeginDraftEditResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($version), 'schema' => new Schema($version->schema_json), 'created' => $created]);
    }

    /** @param array<string, mixed>|object $input */
    public function saveDraftSchema(array|object $input): SaveDraftSchemaResult
    {
        $input = Payload::dto($input, SaveDraftSchemaInput::class);
        $resource = $this->locator->resource((string) ($input['resource'] ?? ''));
        $version = $this->locator->draft($resource);
        $expected = (string) ($input['expected_checksum'] ?? '');
        if ($expected !== '' && $expected !== $version->checksum) {
            throw $this->error('checksum_mismatch', 'draft checksum mismatch', 'draft', ['resource_key' => $resource->resource_key, 'expected_checksum' => $expected, 'current_checksum' => $version->checksum]);
        }
        $schema = new Schema((array) ($input['schema'] ?? []));
        $this->assertValid($schema, 'draft');
        $checksum = $schema->checksum();
        $changed = $checksum !== $version->checksum;
        if ($changed) {
            $version->schema_json = $schema->toArray();
            $version->checksum = $checksum;
            $version->check_status = 'none';
            $version->check_checksum = '';
            $version->validation_result_json = null;
            $version->migration_plan_json = null;
            $version->save();
        }
        return new SaveDraftSchemaResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($version), 'checksum' => $checksum, 'changed' => $changed]);
    }

    public function getActiveVersion(string $resourceKey): GetActiveVersionResult
    {
        $resource = $this->locator->resource($resourceKey);
        $version = $this->locator->active($resource);
        return new GetActiveVersionResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($version), 'schema' => new Schema($version->schema_json)]);
    }

    /** @param array<string, mixed>|object $query */
    public function listVersions(array|object $query): ListVersionsResult
    {
        $query = Payload::dto($query, ListVersionsQuery::class);
        $resource = $this->locator->resource((string) ($query['resource'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $builder = $resource->versions()->orderByDesc('version');
        $total = (clone $builder)->count();
        return new ListVersionsResult(['results' => $builder->forPage($page, $limit)->get()->map(fn (ResourceVersion $version): VersionInfo => $this->locator->versionInfo($version))->all(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /** @param array<string, mixed>|object $query */
    public function getVersion(array|object $query): GetVersionResult
    {
        $query = Payload::dto($query, GetVersionQuery::class);
        $resource = $this->locator->resource((string) ($query['resource'] ?? ''));
        $ref = (array) ($query['version'] ?? []);
        $version = isset($ref['version_id']) ? $resource->versions()->find((int) $ref['version_id']) : $resource->versions()->where('status', $ref['kind'] === 'active' ? 'active' : 'draft')->first();
        if (!$version) {
            throw $this->error('version_not_found', 'version not found', 'resource', ['resource_key' => $resource->resource_key]);
        }
        return new GetVersionResult(['resource' => $resource->resource_key, 'version' => $this->locator->versionInfo($version), 'schema' => new Schema($version->schema_json)]);
    }

    /** @param array<string, mixed>|object $input */
    public function syncCodeResource(array|object $input): SyncCodeResourceResult
    {
        $input = Payload::dto($input, SyncCodeResourceInput::class);
        $key = (string) ($input['resource'] ?? '');
        $schema = new Schema((array) ($input['schema'] ?? []));
        $ownerType = trim((string) ($input['owner_type'] ?? 'app'));
        $ownerCode = trim((string) ($input['owner_code'] ?? 'host'));
        if (!in_array($ownerType, ['app', 'addon', 'core'], true) || $ownerCode === '') {
            throw $this->error('invalid_input', 'code resource owner is invalid', 'resource');
        }
        $resource = Resource::query()->where('resource_key', $key)->first();
        if (!$resource) {
            $parts = explode('.', $key, 2);
            $namespace = count($parts) > 1 ? $parts[0] : '';
            $name = count($parts) > 1 ? $parts[1] : $parts[0];
            $tableName = (string) ($input['table_name'] ?? '');
            $tableName = $tableName !== '' ? $tableName : ($namespace === '' ? $name : $namespace.'_'.$name);
            $create = ['namespace' => $namespace, 'name' => $name, 'title' => $schema->title ?: $name, 'description' => $schema->description ?? '', 'source_type' => 'code', 'owner_type' => $ownerType, 'owner_code' => $ownerCode, 'table_name' => $tableName, 'migration_owner' => $input['migration_owner'] ?? 'schema'];
            if (!empty($input['adopt_existing_table']) && !DB::getSchemaBuilder()->hasTable($tableName)) {
                throw $this->error('invalid_input', 'adopt_existing_table requires an existing table', 'resource', ['resource_key' => $key, 'table_name' => $tableName]);
            }
            return DB::transaction(function () use ($create, $key, $schema): SyncCodeResourceResult {
                $this->createResource($create);
                $saved = $this->saveDraftSchema(['resource' => $key, 'schema' => $schema->toArray()]);

                return new SyncCodeResourceResult([
                    'resource' => $key,
                    'version' => $saved->version,
                    'changed' => true,
                ]);
            });
        }
        if ($resource->source_type !== '' && $resource->source_type !== 'code') {
            throw $this->error('source_type_conflict', 'resource source type conflicts', 'resource', ['resource_key' => $key, 'source_type' => $resource->source_type]);
        }
        if (($resource->owner_type !== '' && $resource->owner_type !== $ownerType) || ($resource->owner_code !== '' && $resource->owner_code !== $ownerCode)) {
            throw $this->error('owner_conflict', 'resource owner conflicts', 'resource', ['resource_key' => $key]);
        }
        $resource->owner_type = $ownerType;
        $resource->owner_code = $ownerCode;
        $tableName = (string) ($input['table_name'] ?? '');
        if ($tableName !== '' && $resource->table_name !== $tableName) {
            if ($resource->active_version_id || $resource->versions()->where('status', 'active')->exists()) {
                throw $this->error('invalid_input', 'published resource table_name cannot be changed', 'resource', ['resource_key' => $key]);
            }
            $resource->table_name = $tableName;
        }
        $migrationOwner = (string) ($input['migration_owner'] ?? '');
        if ($migrationOwner !== '') {
            if (!in_array($migrationOwner, ['schema', 'domain'], true)) {
                throw $this->error('invalid_input', 'migration_owner is invalid', 'resource', ['migration_owner' => $migrationOwner]);
            }
            $resource->migration_owner = $migrationOwner;
        }
        if (!empty($input['adopt_existing_table']) && !DB::getSchemaBuilder()->hasTable($resource->table_name)) {
            throw $this->error('invalid_input', 'adopt_existing_table requires an existing table', 'resource', ['resource_key' => $key, 'table_name' => $resource->table_name]);
        }
        $resource->save();
        if (!$resource->versions()->where('status', 'draft')->exists()) {
            $active = $resource->versions()->where('status', 'active')->first();
            if ($active && hash_equals((string) $active->checksum, $schema->checksum())) {
                return new SyncCodeResourceResult([
                    'resource' => $key,
                    'version' => $this->locator->versionInfo($active),
                    'changed' => false,
                ]);
            }
            $this->beginDraftEdit($key);
        }
        $saved = $this->saveDraftSchema(['resource' => $key, 'schema' => $schema->toArray()]);
        return new SyncCodeResourceResult(['resource' => $key, 'version' => $saved->version, 'changed' => $saved->changed]);
    }

    /**
     * 删除从未发布且未改变存储结构的 Studio 初始草稿；任何发布痕迹都会阻止删除。
     */
    public function deleteDraftResource(string $resourceKey): void
    {
        DB::transaction(function () use ($resourceKey): void {
            $resource = Resource::query()->where('resource_key', trim($resourceKey))->lockForUpdate()->first();
            if (!$resource) {
                throw $this->error('draft_not_found', 'resource not found', 'resource', ['resource_key' => $resourceKey]);
            }
            if ($resource->source_type !== 'studio' || !in_array($resource->owner_type, ['app', 'plugin'], true)) {
                throw $this->draftDeletionError($resource, 'only models created in the schema studio can be deleted');
            }
            if ($resource->status !== 'draft' || (int) $resource->active_version_id !== 0) {
                throw $this->draftDeletionError($resource, 'published or lifecycle-managed resources cannot be deleted');
            }
            $versions = $resource->versions()->lockForUpdate()->get();
            if ($versions->count() !== 1 || $versions->first()->status !== 'draft') {
                throw $this->draftDeletionError($resource, 'resource version history is not an initial draft');
            }
            $tasks = \PTAdmin\Schema\Meta\Models\PublishTask::query()->where('version_id', $versions->first()->id)->lockForUpdate()->get();
            foreach ($tasks as $task) {
                if ($task->status === 'running') {
                    throw $this->draftDeletionError($resource, 'resource publish task is still running');
                }
                if (!in_array($task->status, ['pending', 'failed', 'canceled'], true) || $this->publishTaskHasMigrationEffects((array) ($task->migration_result_json ?? []))) {
                    throw $this->draftDeletionError($resource, 'resource publish history has changed storage');
                }
            }
            if (\PTAdmin\Schema\Meta\Models\FieldProjection::query()->where('resource_id', $resource->id)->exists()) {
                throw $this->draftDeletionError($resource, 'resource has published field projections');
            }
            \PTAdmin\Schema\Meta\Models\PublishTask::query()->where('resource_id', $resource->id)->delete();
            $resource->versions()->delete();
            $resource->delete();
        });
    }

    public function disableResource(string $resourceKey): void
    {
        $this->setStatus($resourceKey, 'disabled');
    }
    public function enableResource(string $resourceKey): void
    {
        $this->setStatus($resourceKey, 'active');
    }
    public function archiveResource(string $resourceKey): void
    {
        $this->setStatus($resourceKey, 'archived');
    }

    private function setStatus(string $key, string $status): void
    {
        $resource = $this->locator->resource($key);
        if ($resource->status === 'archived' && $status !== 'archived') {
            throw $this->error('resource_archived', 'resource is archived', 'resource', ['resource_key' => $key]);
        }
        $resource->status = $status;
        $resource->save();
    }

    /** @param array<string, mixed> $result */
    private function publishTaskHasMigrationEffects(array $result): bool
    {
        if (($result['executed'] ?? false) === true) {
            return true;
        }
        foreach ((array) ($result['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['success'] ?? false) === true && ($step['skipped'] ?? false) !== true) {
                return true;
            }
        }
        return false;
    }

    private function draftDeletionError(Resource $resource, string $message): SchemaError
    {
        return $this->error('invalid_input', $message, 'resource', [
            'resource_key' => $resource->resource_key,
            'source_type' => $resource->source_type,
            'owner_type' => $resource->owner_type,
            'status' => $resource->status,
            'active_version_id' => (int) $resource->active_version_id,
        ]);
    }

    /** 在指定生命周期阶段阻止非法 Schema 进入草稿或发布流程。 */
    private function assertValid(Schema $schema, string $stage): void
    {
        $issues = $schema->validate();
        if ($issues !== []) {
            throw $this->error('invalid_input', $issues[0]['message'], $stage, ['path' => $issues[0]['path'], 'issues' => $issues], $issues);
        }
    }
    private function error(string $code, string $message, string $stage, array $details = [], array $issues = []): SchemaError
    {
        return new SchemaError($code, $message, $details, $issues, false, $stage);
    }
    private function resourceInfo(Resource $resource): ResourceInfo
    {
        $active = $resource->versions()->where('status', 'active')->first();
        $draft = $resource->versions()->where('status', 'draft')->first();
        return new ResourceInfo(['id' => (int) $resource->id, 'resource_key' => $resource->resource_key, 'namespace' => $resource->namespace, 'name' => $resource->name, 'table_name' => $resource->table_name, 'migration_owner' => $resource->migration_owner, 'title' => $resource->title, 'description' => $resource->description, 'source_type' => $resource->source_type, 'owner_type' => $resource->owner_type, 'owner_code' => $resource->owner_code, 'status' => $resource->status, 'active_version_id' => (int) $resource->active_version_id, 'active_version' => $active ? ['version_id' => (int) $active->id] : null, 'has_unpublished_changes' => $active && $draft ? $active->checksum !== $draft->checksum : false, 'created_at' => $resource->created_at, 'updated_at' => $resource->updated_at]);
    }
}
