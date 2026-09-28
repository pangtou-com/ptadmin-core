<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Schema;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use PTAdmin\Admin\Auth\AuthorizationResourceCatalog;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Models\AdminRole;
use PTAdmin\Admin\Models\AdminResource;
use PTAdmin\Core\Auth\DataScopeRegistry;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Notice\NoticeTemplateException;
use PTAdmin\Notice\NoticeTemplateValidator;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Runtime\RuntimeHookContext;

/**
 * Admin 管理与审计资源的领域规则。普通字段仍由 Runtime 校验，敏感字段和关联写入在这里集中处理。
 */
final class AdminRuntimeHook
{
    public function __construct(
        private ConnectionInterface $database,
        private SessionManager $sessions,
        private DataScopeRegistry $scopes,
        private AuthorizationResourceCatalog $authorizationResources,
        private NoticeTemplateValidator $noticeTemplates,
    ) {
    }

    public function prepareCreate(RuntimeHookContext $context, array $prepared): array
    {
        [$resourceKey, $input, $schema] = $this->contextValues($context);
        $this->assertWritable($resourceKey);
        $timestamp = in_array($resourceKey, [
            'admin.dashboard_widgets',
            'admin.notification_templates',
        ], true) ? time() : now();
        $prepared['created_at'] = $timestamp;
        $prepared['updated_at'] = $timestamp;
        if ($resourceKey === 'admin.admin_users') {
            if (Admin::query()->where('username', (string) ($prepared['username'] ?? ''))->exists()) {
                throw $this->error('username_conflict', '管理员用户名已经存在。', ['field' => 'username']);
            }
            $password = (string) ($input['password'] ?? '');
            if ($password === '') {
                throw $this->error('invalid_input', '管理员密码不能为空。', ['field' => 'password']);
            }
            $prepared['password'] = Hash::make($password);
            $prepared['is_founder'] = false;
        }
        if ($resourceKey === 'admin.admin_roles') {
            if (AdminRole::query()->where('code', (string) ($prepared['code'] ?? ''))->exists()) {
                throw $this->error('role_code_conflict', '角色编码已经存在。', ['field' => 'code']);
            }
            $prepared['is_system'] = false;
        }
        if ($resourceKey === 'admin.admin_resources') {
            if (AdminResource::query()->where('resource_key', (string) ($prepared['resource_key'] ?? ''))->exists()) {
                throw $this->error('resource_key_conflict', '权限资源编码已经存在。', ['field' => 'resource_key']);
            }
            $prepared = $this->prepareAdminResource($input, $prepared);
        }
        if ($resourceKey === 'admin.notification_templates') {
            $prepared = $this->validateNoticeTemplate($prepared);
        }

        return $prepared;
    }

    public function created(RuntimeHookContext $context): void
    {
        [$resourceKey, $input, $schema, $id] = $this->contextValues($context, true);
        if ($resourceKey === 'admin.admin_users') {
            $this->syncUserRelations($id, $input);
        }
    }

    public function prepareUpdate(RuntimeHookContext $context, array $prepared): array
    {
        [$resourceKey, $input, $schema, $id] = $this->contextValues($context, true);
        $this->assertWritable($resourceKey);
        $prepared['updated_at'] = in_array($resourceKey, [
            'admin.dashboard_widgets',
            'admin.notification_templates',
        ], true) ? time() : now();
        if ($resourceKey === 'admin.admin_users') {
            $admin = Admin::query()->findOrFail($id);
            if ((bool) $admin->getAttribute('is_founder') && array_key_exists('status', $input) && !(bool) $input['status']) {
                throw $this->error('founder_protected', '创始人账号不能被禁用。', ['id' => $id]);
            }
            if (array_key_exists('is_founder', $input) && (bool) $input['is_founder'] !== (bool) $admin->getAttribute('is_founder')
                || array_key_exists('username', $input) && (string) $input['username'] !== (string) $admin->username) {
                throw $this->error('founder_protected', '管理员用户名和创始人标记不能通过普通编辑修改。', ['id' => $id]);
            }
            if (array_key_exists('password', $input)) {
                $password = (string) $input['password'];
                if ($password === '') {
                    throw $this->error('invalid_input', '管理员密码不能为空。', ['field' => 'password']);
                }
                $prepared['password'] = Hash::make($password);
            }
        } elseif ($resourceKey === 'admin.admin_roles') {
            $role = AdminRole::query()->findOrFail($id);
            if (array_key_exists('code', $input) && (string) $input['code'] !== (string) $role->code) {
                throw $this->error('role_code_immutable', '角色编码创建后不能修改。', ['id' => $id]);
            }
            if ($role->is_system && (
                array_key_exists('is_system', $input) && (bool) $input['is_system'] !== (bool) $role->is_system
                || array_key_exists('status', $input) && !(bool) $input['status']
            )) {
                throw $this->error('system_role_protected', '系统角色标记和状态不能修改。', ['id' => $id]);
            }
        } elseif ($resourceKey === 'admin.admin_resources') {
            $resource = AdminResource::query()->findOrFail($id);
            if (array_key_exists('resource_key', $input) && (string) $input['resource_key'] !== (string) $resource->resource_key) {
                throw $this->error('resource_key_immutable', '权限资源编码创建后不能修改。', ['id' => $id]);
            }
            $prepared = $this->prepareAdminResource($input, $prepared, $resource);
        } elseif ($resourceKey === 'admin.notification_templates') {
            $prepared = $this->validateNoticeTemplate($prepared, $id);
        }

        return $prepared;
    }

    public function updated(RuntimeHookContext $context): void
    {
        [$resourceKey, $input, $schema, $id] = $this->contextValues($context, true);
        if ($resourceKey === 'admin.admin_users') {
            $this->syncUserRelations($id, $input);
            if (array_key_exists('status', $input) && !(bool) $input['status']) {
                $this->sessions->revokeSubject(Admin::class, $id, 'admin');
            }
        }
    }

    public function deleting(RuntimeHookContext $context): void
    {
        [$resourceKey, , $schema, $id] = $this->contextValues($context, true);
        $this->assertWritable($resourceKey);
        if ($resourceKey === 'admin.admin_users') {
            $admin = $context->stage === 'purge.before'
                ? Admin::withTrashed()->findOrFail($id)
                : Admin::query()->findOrFail($id);
            if ((bool) $admin->getAttribute('is_founder')) {
                throw $this->error('founder_protected', '创始人账号不能删除。', ['id' => $id]);
            }
            $this->sessions->revokeSubject(Admin::class, $id, 'admin');
        } elseif ($resourceKey === 'admin.admin_roles') {
            $role = $context->stage === 'purge.before'
                ? AdminRole::withTrashed()->findOrFail($id)
                : AdminRole::query()->findOrFail($id);
            if ($role->is_system) {
                throw $this->error('system_role_protected', '系统角色不能删除。', ['id' => $id]);
            }
        } elseif ($resourceKey === 'admin.admin_resources') {
            $resource = $context->stage === 'purge.before'
                ? AdminResource::withTrashed()->findOrFail($id)
                : AdminResource::query()->findOrFail($id);
            $this->assertResourceCanBeRemoved($resource);
        }
    }

    public function deleted(RuntimeHookContext $context): void
    {
        [$resourceKey, , $schema, $id] = $this->contextValues($context, true);
        if ($resourceKey === 'admin.admin_users') {
            $this->database->table('admin_user_roles')->where('admin_id', $id)->delete();
            $this->database->table('admin_grants')->where('subject_type', 'admin')->where('subject_id', (string) $id)->delete();
        } elseif ($resourceKey === 'admin.admin_roles') {
            $this->database->table('admin_user_roles')->where('role_id', $id)->delete();
            $this->database->table('admin_grants')->where('subject_type', 'role')->where('subject_id', (string) $id)->delete();
        }
    }

    public function output(RuntimeHookContext $context, array $row): array
    {
        [$resourceKey, , $schema] = $this->contextValues($context);
        if ($resourceKey === 'admin.admin_users') {
            unset($row['password']);
            $row['role_ids'] = $this->database->table('admin_user_roles')->where('admin_id', (int) ($row['id'] ?? 0))->pluck('role_id')->map(static fn ($id): int => (int) $id)->values()->all();
            $row['permissions'] = $this->permissionPayload('admin', (string) ($row['id'] ?? 0));
        } elseif ($resourceKey === 'admin.admin_roles') {
            $row['permissions'] = $this->permissionPayload('role', (string) ($row['id'] ?? 0));
        } elseif ($resourceKey === 'admin.admin_resources') {
            $meta = $this->decodeMeta($row['meta_json'] ?? null);
            $capabilities = $this->authorizationResources->capabilities($row);
            unset($row['meta_json']);
            $row['target_resource_key'] = (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '');
            $row['icon'] = (string) ($row['icon'] ?? $meta['icon'] ?? '');
            $row['external_url'] = (string) ($meta['external_url'] ?? '');
            $row['page_type'] = (string) ($meta['page_type'] ?? $this->legacyPageType((string) ($row['type'] ?? ''), (string) ($row['page_key'] ?? '')));
            $row['owner_type'] = (string) ($meta['owner_type'] ?? ($this->isCoreResource((string) ($row['resource_key'] ?? '')) ? 'core' : 'custom'));
            $row['owner_code'] = (string) ($meta['owner_code'] ?? '');
            $row['is_core'] = $this->isCoreResource((string) ($row['resource_key'] ?? ''));
            $row['abilities'] = $capabilities['abilities'];
            $row['scopes'] = $capabilities['scopes'];
        }

        return $row;
    }

    private function legacyPageType(string $type, string $pageKey): string
    {
        return match ($type) {
            'group' => 'navigation.group',
            'schema' => 'schema.resource',
            'plugin' => 'plugin.page',
            'external' => 'external.link',
            default => $pageKey,
        };
    }

    public function executeAction(RuntimeHookContext $context, array $input): array
    {
        [$resourceKey, , $schema] = $this->contextValues($context);
        $action = (string) $context->actionKey;
        $id = (int) ($input['id'] ?? 0);
        if ($resourceKey === 'admin.admin_users' && $action === 'sync_roles') {
            $this->admin($id);
            $this->syncUserRelations($id, (array) ($input['data'] ?? []));
            return ['id' => $id, 'role_ids' => $this->database->table('admin_user_roles')->where('admin_id', $id)->pluck('role_id')->map(static fn ($value): int => (int) $value)->values()->all()];
        }
        if ($resourceKey === 'admin.admin_users' && $action === 'sync_permissions') {
            $this->admin($id);
            $this->syncAdminPermissions($id, (array) (($input['data'] ?? [])['permissions'] ?? []));
            return ['id' => $id, 'permissions' => $this->permissionPayload('admin', (string) $id)];
        }
        if ($resourceKey === 'admin.admin_users' && $action === 'reset_password') {
            $this->admin($id);
            $password = (string) (($input['data'] ?? [])['password'] ?? '');
            if (strlen($password) < 6 || strlen($password) > 4096) {
                throw $this->error('invalid_input', '新密码长度必须为 6 到 4096 个字符。', ['field' => 'password']);
            }
            Admin::query()->whereKey($id)->update(['password' => Hash::make($password), 'updated_at' => now()]);
            $this->sessions->revokeSubject(Admin::class, $id, 'admin');
            return ['id' => $id, 'success' => true];
        }
        if ($resourceKey === 'admin.admin_users' && $action === 'revoke_sessions') {
            $this->admin($id);
            return ['id' => $id, 'revoked' => $this->sessions->revokeSubject(Admin::class, $id, 'admin')];
        }
        if ($resourceKey === 'admin.admin_roles' && $action === 'sync_permissions') {
            $this->role($id);
            $this->syncRolePermissions($id, (array) (($input['data'] ?? [])['permissions'] ?? []));
            return ['id' => $id, 'permissions' => $this->permissionPayload('role', (string) $id)];
        }
        if ($resourceKey === 'admin.admin_resources' && in_array($action, ['move_resource', 'move_menu'], true)) {
            return $this->moveResource($id, (array) ($input['data'] ?? []), $schema);
        }

        throw $this->error('action_not_available', 'Admin 动作未实现。', ['resource_key' => $resourceKey, 'action' => $action]);
    }

    private function syncUserRelations(int $adminId, array $input): void
    {
        if (array_key_exists('role_ids', $input)) {
            $roleIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $input['role_ids']),
                static fn (int $id): bool => $id > 0,
            )));
            $validRoleIds = $this->database->table('admin_roles')
                ->whereIn('id', $roleIds)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            sort($roleIds);
            sort($validRoleIds);
            if ($roleIds !== $validRoleIds) {
                throw $this->error('invalid_role_selection', '角色选择包含不存在或已停用的角色。', ['role_ids' => $roleIds]);
            }

            $admin = $this->admin($adminId);
            if ((bool) $admin->getAttribute('is_founder')) {
                $currentRoleIds = $this->database->table('admin_user_roles')
                    ->where('admin_id', $adminId)
                    ->pluck('role_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all();
                sort($currentRoleIds);
                if ($roleIds !== $currentRoleIds) {
                    throw $this->error('founder_protected', '创始人账号不能设置角色。', ['id' => $adminId]);
                }
            }

            $this->database->transaction(function () use ($adminId, $roleIds): void {
                $this->database->table('admin_user_roles')->where('admin_id', $adminId)->delete();
                foreach ($roleIds as $roleId) {
                    $this->database->table('admin_user_roles')->insert([
                        'admin_id' => $adminId,
                        'role_id' => $roleId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }
        if (array_key_exists('permissions', $input)) {
            $this->syncAdminPermissions($adminId, (array) $input['permissions']);
        }
    }

    private function syncAdminPermissions(int $adminId, array $permissions): void
    {
        $this->replacePermissions('admin', (string) $adminId, $permissions);
    }

    private function syncRolePermissions(int $roleId, mixed $permissions): void
    {
        $this->replacePermissions('role', (string) $roleId, (array) $permissions);
    }

    private function replacePermissions(string $subjectType, string $subjectId, array $permissions): void
    {
        $normalized = [];
        foreach ($permissions as $permission) {
            if (!is_array($permission)) {
                throw $this->error('invalid_permission', '权限项必须是对象。');
            }
            $resourceKey = (string) ($permission['resource_key'] ?? '');
            $resource = $this->database->table('admin_resources')
                ->whereNull('deleted_at')
                ->where('resource_key', $resourceKey)
                ->first(['id', 'type']);
            if (!$resource) {
                throw $this->error('invalid_permission', '权限资源不存在。', ['resource_key' => $resourceKey]);
            }
            $abilities = array_values(array_unique(array_map('strval', (array) ($permission['abilities'] ?? []))));
            if ($abilities === [] || collect($abilities)->contains(static fn (string $ability): bool => $ability !== '*' && preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $ability) !== 1)) {
                throw $this->error('invalid_permission', '权限动作列表不合法。', ['resource_key' => $resourceKey]);
            }
            $effect = (string) ($permission['effect'] ?? 'allow');
            if (!in_array($effect, ['allow', 'deny'], true)) {
                throw $this->error('invalid_permission', '权限效果只支持 allow 或 deny。', ['resource_key' => $resourceKey]);
            }
            $scopeType = $this->nullableString($permission['scope_type'] ?? null);
            if ((string) $resource->type === 'schema' && $scopeType === null) {
                throw $this->error('invalid_permission', 'Schema 资源授权必须明确数据范围。', ['resource_key' => $resourceKey]);
            }
            $scopeType ??= 'all';
            $scopeValue = $permission['scope_value'] ?? null;
            if ($scopeValue !== null && !is_array($scopeValue)) {
                throw $this->error('invalid_permission', '权限数据范围参数必须是对象或数组。', ['resource_key' => $resourceKey]);
            }
            $conditions = $permission['conditions'] ?? null;
            if ($conditions !== null && (!is_array($conditions) || !$this->validConditions($conditions))) {
                throw $this->error('invalid_permission', '权限附加条件格式不合法。', ['resource_key' => $resourceKey]);
            }
            $this->assertPermissionScope($scopeType, $scopeValue);
            $expiresAt = $this->normalizeExpiresAt($permission['expires_at'] ?? null);
            $priority = (int) ($permission['priority'] ?? ($subjectType === 'admin' ? 100 : 0));
            if ($priority < 0 || $priority > 10000) {
                throw $this->error('invalid_permission', '权限优先级必须在 0 到 10000 之间。', ['priority' => $priority]);
            }
            $normalized[] = [
                'resource_id' => (int) $resource->id,
                'effect' => $effect,
                'abilities' => $abilities,
                'priority' => $priority,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'conditions' => $conditions,
                'expires_at' => $expiresAt,
            ];
        }
        $this->database->transaction(function () use ($subjectType, $subjectId, $normalized): void {
            $this->database->table('admin_grants')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->delete();
            foreach ($normalized as $permission) {
                $this->database->table('admin_grants')->insert([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'resource_id' => $permission['resource_id'],
                    'effect' => $permission['effect'],
                    'abilities_json' => json_encode($permission['abilities'], JSON_THROW_ON_ERROR),
                    'priority' => $permission['priority'],
                    'scope_type' => $permission['scope_type'],
                    'scope_value_json' => $permission['scope_value'] === null ? null : json_encode($permission['scope_value'], JSON_THROW_ON_ERROR),
                    'conditions_json' => $permission['conditions'] === null ? null : json_encode($permission['conditions'], JSON_THROW_ON_ERROR),
                    'expires_at' => $permission['expires_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    private function permissionPayload(string $subjectType, string $subjectId): array
    {
        return $this->database->table('admin_grants as grants')->join('admin_resources as resources', 'resources.id', '=', 'grants.resource_id')->whereNull('resources.deleted_at')->where('grants.subject_type', $subjectType)->where('grants.subject_id', $subjectId)->orderBy('grants.id')->get(['resources.resource_key', 'grants.effect', 'grants.abilities_json', 'grants.priority', 'grants.scope_type', 'grants.scope_value_json', 'grants.conditions_json', 'grants.expires_at'])->map(static function ($grant): array {
            return [
                'resource_key' => (string) $grant->resource_key,
                'effect' => (string) $grant->effect,
                'abilities' => (array) json_decode((string) $grant->abilities_json, true),
                'priority' => (int) $grant->priority,
                'scope_type' => $grant->scope_type === null ? null : (string) $grant->scope_type,
                'scope_value' => $grant->scope_value_json === null ? null : json_decode((string) $grant->scope_value_json, true),
                'conditions' => $grant->conditions_json === null ? null : json_decode((string) $grant->conditions_json, true),
                'expires_at' => $grant->expires_at === null ? null : (string) $grant->expires_at,
            ];
        })->all();
    }

    /** @param array<string, mixed>|list<mixed>|null $scopeValue */
    private function assertPermissionScope(string $scopeType, ?array $scopeValue): void
    {
        if (in_array($scopeType, ['all', 'self'], true)) {
            if ($scopeValue !== null && $scopeValue !== []) {
                throw $this->error('invalid_permission', '内置数据范围不接受额外参数。', ['scope_type' => $scopeType]);
            }

            return;
        }
        if (!$this->scopes->has($scopeType) || !$this->scopes->validate($scopeType, $scopeValue)) {
            throw $this->error('invalid_permission', '数据范围未注册或参数不合法。', ['scope_type' => $scopeType]);
        }
    }

    /** @param array<string, mixed>|list<mixed> $conditions */
    private function validConditions(array $conditions, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        if (array_key_exists('all', $conditions) && array_key_exists('any', $conditions)) {
            return false;
        }
        foreach (['all', 'any'] as $group) {
            if (!array_key_exists($group, $conditions)) {
                continue;
            }
            if (!is_array($conditions[$group]) || $conditions[$group] === []) {
                return false;
            }
            foreach ($conditions[$group] as $child) {
                if (!is_array($child) || !$this->validConditions($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return in_array((string) ($conditions['field'] ?? ''), ['admin_id', 'ability'], true)
            && in_array((string) ($conditions['operator'] ?? 'eq'), ['eq', 'neq', 'in', 'not_in'], true)
            && array_key_exists('value', $conditions);
    }

    private function normalizeExpiresAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strtotime($value) === false) {
            throw $this->error('invalid_permission', '权限失效时间格式不正确。', ['expires_at' => $value]);
        }

        return date('Y-m-d H:i:s', (int) strtotime($value));
    }

    /**
     * 把资源表单中的结构化字段收敛回 meta_json，并在写入前验证整条资源记录。
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private function prepareAdminResource(array $input, array $prepared, ?AdminResource $current = null): array
    {
        // Accept the pre-schema page_type field at this API boundary while storing only the new type model.
        $legacyPageType = (string) ($input['page_type'] ?? '');
        if (($prepared['type'] ?? $current?->type) === 'page' && $legacyPageType !== '') {
            if ($legacyPageType === 'navigation.group') {
                $prepared['type'] = 'group';
            } elseif ($legacyPageType === 'schema.resource') {
                $prepared['type'] = 'schema';
                $target = (string) ($input['target_resource_key'] ?? $input['resource_key'] ?? '');
                if ($target !== '' && !array_key_exists('target_resource_key', $input)) {
                    $input['target_resource_key'] = $target;
                }
            } elseif ($legacyPageType === 'external.link') {
                $prepared['type'] = 'external';
            } elseif ($legacyPageType !== '') {
                $prepared['page_key'] = $prepared['page_key'] ?? $legacyPageType;
            }
        }
        $currentAttributes = $current?->getAttributes() ?? [];
        $meta = $this->decodeMeta($currentAttributes['meta_json'] ?? null);
        $metaFields = [
            'target_resource_key' => 'target_resource_key',
            'external_url' => 'external_url',
        ];
        foreach ($metaFields as $inputField => $metaField) {
            if (!array_key_exists($inputField, $input)) {
                continue;
            }
            $value = trim((string) $input[$inputField]);
            if ($value === '') {
                unset($meta[$metaField]);
            } else {
                $meta[$metaField] = $value;
            }
        }
        if (array_key_exists('page_type', $input) && (string) $input['page_type'] !== '') {
            $meta['page_type'] = (string) $input['page_type'];
        }

        if (($prepared['type'] ?? $current?->type) !== 'schema') {
            unset($meta['target_resource_key']);
        }
        if (($prepared['type'] ?? $current?->type) !== 'external') {
            unset($meta['external_url']);
        }

        if ($current && ($this->isCoreResource((string) $current->resource_key) || ($meta['owner_type'] ?? null) === 'addon')) {
            $currentMeta = $this->decodeMeta($currentAttributes['meta_json'] ?? null);
            if ((string) ($prepared['type'] ?? $current->type) !== (string) $current->type
                || (bool) ($prepared['is_menu'] ?? $current->is_menu) !== (bool) $current->is_menu
                || (string) ($prepared['page_key'] ?? $current->page_key) !== (string) ($current->page_key ?? '')
                || (string) ($meta['target_resource_key'] ?? '') !== (string) ($currentMeta['target_resource_key'] ?? $currentMeta['resource_key'] ?? '')
            ) {
                throw $this->error('resource_binding_protected', '核心或插件资源的类型和页面绑定不能修改。', [
                    'resource_key' => (string) $current->resource_key,
                ]);
            }
        }

        $prepared['meta_json'] = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $next = array_merge($currentAttributes, $prepared);
        $next['meta_json'] = $meta;
        $this->assertAdminResourceDefinition($next, (int) ($current?->getKey() ?? 0));

        return $prepared;
    }

    /** @param array<string, mixed> $resource */
    private function assertAdminResourceDefinition(array $resource, int $currentId = 0): void
    {
        $resourceKey = trim((string) ($resource['resource_key'] ?? ''));
        $type = (string) ($resource['type'] ?? '');
        $parentKey = trim((string) ($resource['parent_key'] ?? ''));
        $path = trim((string) ($resource['path'] ?? ''));
        $isMenu = (bool) ($resource['is_menu'] ?? false);
        $status = (bool) ($resource['status'] ?? false);
        $meta = $this->decodeMeta($resource['meta_json'] ?? null);

        if (!in_array($type, ['group', 'schema', 'page', 'plugin', 'external', 'action'], true)) {
            throw $this->error('invalid_menu_resource', '资源类型只能是分组、Schema、插件页面、宿主页面、外链或操作权限。', ['field' => 'type']);
        }
        $this->assertParentResource($resourceKey, $parentKey);

        if ($type === 'action') {
            if ($isMenu || $path !== '' || ($resource['page_key'] ?? '') !== '' || ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '') !== '' || ($meta['external_url'] ?? '') !== '') {
                throw $this->error('invalid_resource_page_fields', '操作权限不能配置导航页面字段。', ['field' => 'type']);
            }
            return;
        }
        if ($type === 'group' && (($resource['page_key'] ?? '') !== '' || ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '') !== '' || ($meta['external_url'] ?? '') !== '')) {
            throw $this->error('invalid_resource_page_fields', '导航分组不能配置页面绑定。', ['field' => 'type']);
        }
        if ($type === 'plugin' && (($resource['page_key'] ?? '') !== '' || ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '') !== '' || ($meta['external_url'] ?? '') !== '')) {
            throw $this->error('invalid_resource_page_fields', '插件页面不能配置 page_key、target_resource_key 或 external_url。', ['field' => 'type']);
        }
        if ($type === 'plugin' && (string) ($resource['addon_code'] ?? '') === '') {
            throw $this->error('invalid_plugin_resource', '插件页面必须归属于已安装插件。', ['field' => 'addon_code']);
        }
        if ($type === 'schema' && (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '') === '') {
            throw $this->error('invalid_schema_resource', 'Schema 资源必须绑定目标资源。', ['field' => 'target_resource_key']);
        }
        if ($type === 'page' && $isMenu && (string) ($resource['page_key'] ?? '') === '') {
            throw $this->error('invalid_page_binding', '内部页面必须绑定 page_key。', ['field' => 'page_key']);
        }
        if ($type === 'external' && (string) ($meta['external_url'] ?? '') === '') {
            throw $this->error('invalid_external_url', '外链必须配置 external_url。', ['field' => 'external_url']);
        }
        if ($isMenu) {
            if ($type !== 'group' && ($path === '' || !preg_match('!^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$!', $path))) {
                throw $this->error('invalid_resource_path', '菜单路径必须是以 / 开头且不包含查询参数的后台相对路径。', ['field' => 'path']);
            }
            $pathQuery = AdminResource::query()->where('is_menu', true)->where('path', $path)->where('path', '<>', '');
            if ($currentId > 0) {
                $pathQuery->whereKeyNot($currentId);
            }
            if ($pathQuery->exists()) {
                throw $this->error('resource_path_conflict', '菜单访问路径已经被其他菜单使用。', ['field' => 'path']);
            }
        }

        if ($isMenu && $parentKey !== '') {
            $parent = AdminResource::query()->where('resource_key', $parentKey)->first();
            if (!$parent || !$parent->is_menu || !in_array($parent->type, ['group', 'schema', 'page', 'plugin'], true)) {
                throw $this->error('invalid_parent_resource', '菜单父级必须是未删除的页面菜单。', ['parent_key' => $parentKey]);
            }
            if ($status && !$parent->status) {
                throw $this->error('inactive_parent_resource', '启用菜单不能放在已停用的父菜单下。', ['parent_key' => $parentKey]);
            }
        }

        $builtinKeys = [
            'dashboard' => 'dashboard',
            'asset.center' => 'assets',
            'settings.center' => 'admin.config',
            'dictionary.center' => 'admin.dictionaries',
            'logs.center' => 'admin.logs',
            'theme.center' => 'themes',
            'schema.design' => 'schema.resources',
        ];
        $pageKey = (string) ($resource['page_key'] ?? '');
        if ($type === 'page' && isset($builtinKeys[$pageKey]) && $resourceKey !== $builtinKeys[$pageKey]) {
            throw $this->error('invalid_page_binding', '内置页面只能绑定其约定的资源编码。', [
                'resource_key' => $resourceKey,
                'expected_resource_key' => $builtinKeys[$pageKey],
            ]);
        }

        $targetResourceKey = (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '');
        if ($type === 'schema') {
            $legacyDesignBinding = $resourceKey === 'schema.resources' && $targetResourceKey === 'schema.resource';
            if ($targetResourceKey === '' || (!$legacyDesignBinding && !$this->database->table('schema_resources')
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->where('resource_key', $targetResourceKey)
                ->exists())) {
                throw $this->error('invalid_schema_resource', 'Schema 资源页必须绑定已发布且启用的目标资源。', [
                    'field' => 'target_resource_key',
                    'target_resource_key' => $targetResourceKey,
                ]);
            }
        }
        if ($type === 'external') {
            $externalUrl = (string) ($meta['external_url'] ?? '');
            if ($externalUrl !== '' && (!filter_var($externalUrl, FILTER_VALIDATE_URL)
                || !in_array((string) parse_url($externalUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw $this->error('invalid_external_url', '外部链接只支持有效的 HTTP 或 HTTPS 地址。', ['field' => 'external_url']);
            }
        }

        if (!$status) {
            $enabledChildren = AdminResource::query()
                ->where('parent_key', $resourceKey)
                ->where('is_menu', true)
                ->where('status', true)
                ->exists();
            if ($enabledChildren) {
                throw $this->error('resource_has_enabled_children', '请先停用子菜单，再停用当前菜单。', ['resource_key' => $resourceKey]);
            }
        }
    }

    /** @return list<string> */
    private function menuPageTypes(): array
    {
        return [
            'navigation.group', 'schema.resource', 'dashboard', 'asset.center', 'settings.center',
            'dictionary.center', 'logs.center', 'theme.center', 'schema.design',
            'plugin.page', 'cloud.market', 'external.link',
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function moveResource(int $id, array $input, Schema $schema): array
    {
        $resource = AdminResource::query()->findOrFail($id);

        $parentKey = trim((string) ($input['parent_key'] ?? ''));
        $beforeKey = trim((string) ($input['before_key'] ?? ''));
        $afterKey = trim((string) ($input['after_key'] ?? ''));
        if ($beforeKey !== '' && $afterKey !== '') {
            throw $this->error('invalid_input', '移动资源不能同时指定前置和后置锚点。');
        }

        $next = $resource->getAttributes();
        $next['parent_key'] = $parentKey;
        $next['meta_json'] = $this->decodeMeta($next['meta_json'] ?? null);
        $this->assertAdminResourceDefinition($next, $id);

        $siblings = AdminResource::query()
            ->where('parent_key', $parentKey)
            ->whereKeyNot($id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $orderedKeys = $siblings->pluck('resource_key')->map(static fn ($key): string => (string) $key)->all();
        $position = count($orderedKeys);
        $anchorKey = $beforeKey !== '' ? $beforeKey : $afterKey;
        if ($anchorKey !== '') {
            $anchorPosition = array_search($anchorKey, $orderedKeys, true);
            if ($anchorPosition === false) {
                throw $this->error('invalid_move_anchor', '资源移动锚点不在目标父级下。', ['anchor_key' => $anchorKey]);
            }
            $position = (int) $anchorPosition + ($afterKey !== '' ? 1 : 0);
        }
        array_splice($orderedKeys, $position, 0, [(string) $resource->resource_key]);

        $this->database->transaction(function () use ($orderedKeys, $parentKey): void {
            foreach ($orderedKeys as $index => $resourceKey) {
                $this->database->table('admin_resources')->where('resource_key', $resourceKey)->update([
                    'parent_key' => $parentKey,
                    'sort' => ($index + 1) * 10,
                    'updated_at' => now(),
                ]);
            }
        });

        $moved = AdminResource::query()->findOrFail($id)->getAttributes();

        return ['id' => $id, 'item' => $this->output(new RuntimeHookContext('admin.admin_resources', 'output.transform', $schema), $moved)];
    }

    private function assertResourceCanBeRemoved(AdminResource $resource): void
    {
        if ($this->isCoreResource((string) $resource->resource_key)) {
            throw $this->error('core_resource_protected', '核心权限资源不能删除。', ['id' => (int) $resource->getKey()]);
        }
        if (AdminResource::withTrashed()->where('parent_key', $resource->resource_key)->exists()) {
            throw $this->error('resource_has_children', '存在子资源时不能删除当前资源。', ['id' => (int) $resource->getKey()]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    /** @param array<string, mixed> $prepared @return array<string, mixed> */
    private function validateNoticeTemplate(array $prepared, ?int $recordId = null): array
    {
        try {
            return $this->noticeTemplates->validate($prepared, $recordId);
        } catch (NoticeTemplateException $exception) {
            throw $this->error('invalid_input', $exception->getMessage(), $exception->details);
        }
    }

    private function error(string $code, string $message, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details);
    }

    /** @return array{0:string,1:array<string,mixed>,2:Schema,3:int|null} */
    private function contextValues(RuntimeHookContext $context, bool $requiresRecord = false): array
    {
        if (!$context->schema instanceof Schema) {
            throw $this->error('runtime_context_invalid', 'Runtime Hook context lacks a Schema.', ['resource_key' => $context->resourceKey]);
        }
        if ($requiresRecord && ($context->recordId === null || $context->recordId < 1)) {
            throw $this->error('runtime_context_invalid', 'Runtime Hook context lacks a record ID.', ['resource_key' => $context->resourceKey]);
        }

        return [$context->resourceKey, $context->input, $context->schema, $context->recordId];
    }

    private function isCoreResource(string $resourceKey): bool
    {
        return in_array($resourceKey, [
            'dashboard', 'assets', 'site', 'themes', 'admin.users', 'admin.roles', 'admin.menus', 'admin.logs',
            'admin.dictionaries',
            'admin.notifications', 'admin.notification_templates',
            'admin.admin_users', 'admin.admin_roles', 'admin.admin_resources',
            'admin.dashboard_widgets', 'admin.notification_scenes', 'admin.notification_messages',
            'admin.notification_deliveries', 'admin.notice_channels',
            'schema.resources', 'admin.config', 'core.tasks', 'core.update',
            'admin.frontend', 'addon.manage', 'addon.download', 'addon.install',
            'addon.update', 'addon.uninstall', 'addon.frontend', 'addon.enable', 'addon.disable',
        ], true);
    }

    private function assertWritable(string $resourceKey): void
    {
        if (in_array($resourceKey, [
            'admin.notification_scenes',
            'admin.notice_channels',
            'admin.login_logs',
            'admin.operation_records',
            'admin.notification_messages',
            'admin.notification_deliveries',
        ], true)) {
            throw $this->error('read_only_resource', '该资源由系统维护，只允许查询。', ['resource_key' => $resourceKey]);
        }
    }

    private function admin(int $id): Admin
    {
        $admin = Admin::query()->find($id);
        if (!$admin) {
            throw $this->error('record_not_found', '管理员不存在。', ['id' => $id]);
        }

        return $admin;
    }

    private function role(int $id): AdminRole
    {
        $role = AdminRole::query()->find($id);
        if (!$role) {
            throw $this->error('record_not_found', '角色不存在。', ['id' => $id]);
        }

        return $role;
    }

    private function assertParentResource(string $resourceKey, string $parentKey): void
    {
        if ($parentKey === '') {
            return;
        }
        if ($parentKey === $resourceKey) {
            throw $this->error('resource_parent_cycle', '权限资源不能将自身设为父级。', ['resource_key' => $resourceKey]);
        }
        if (!AdminResource::query()->where('resource_key', $parentKey)->exists()) {
            throw $this->error('invalid_parent_resource', '父权限资源不存在。', ['parent_key' => $parentKey]);
        }
        $seen = [$resourceKey => true];
        while ($parentKey !== '') {
            if (isset($seen[$parentKey])) {
                throw $this->error('resource_parent_cycle', '权限资源父级关系形成循环。', ['resource_key' => $resourceKey]);
            }
            $seen[$parentKey] = true;
            $parentKey = (string) AdminResource::query()->where('resource_key', $parentKey)->value('parent_key');
        }
    }
}
