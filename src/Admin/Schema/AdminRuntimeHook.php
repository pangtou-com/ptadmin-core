<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Schema;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Models\AdminRole;
use PTAdmin\Admin\Models\AdminResource;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contracts\RuntimeResourceHookInterface;
use PTAdmin\Schema\Definition\Schema;

/**
 * Admin 管理与审计资源的领域规则。普通字段仍由 Runtime 校验，敏感字段和关联写入在这里集中处理。
 */
final class AdminRuntimeHook implements RuntimeResourceHookInterface
{
    public function __construct(private ConnectionInterface $database, private SessionManager $sessions)
    {
    }

    public function resources(): array
    {
        return [
            'admin.management_users',
            'admin.management_roles',
            'admin.management_menus',
            'admin.login_logs',
            'admin.operation_records',
            'admin.management_tenants',
            'admin.management_organizations',
            'admin.management_departments',
            'admin.dashboard_widgets',
            'admin.notification_scenes',
            'admin.notification_templates',
            'admin.notification_messages',
            'admin.notification_receipts',
            'admin.notification_deliveries',
        ];
    }

    public function prepareCreate(string $resourceKey, array $input, array $prepared, Schema $schema): array
    {
        $this->assertWritable($resourceKey);
        $timestamp = in_array($resourceKey, [
            'admin.management_tenants',
            'admin.management_organizations',
            'admin.management_departments',
            'admin.dashboard_widgets',
            'admin.notification_scenes',
            'admin.notification_templates',
        ], true) ? time() : now();
        $prepared['created_at'] = $timestamp;
        $prepared['updated_at'] = $timestamp;
        if ($resourceKey === 'admin.management_users') {
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
        if ($resourceKey === 'admin.management_roles') {
            if (AdminRole::query()->where('code', (string) ($prepared['code'] ?? ''))->exists()) {
                throw $this->error('role_code_conflict', '角色编码已经存在。', ['field' => 'code']);
            }
            $prepared['is_system'] = false;
            $this->assertRoleScope($prepared);
        }
        if ($resourceKey === 'admin.management_menus') {
            if (AdminResource::query()->where('resource_key', (string) ($prepared['resource_key'] ?? ''))->exists()) {
                throw $this->error('resource_key_conflict', '权限资源编码已经存在。', ['field' => 'resource_key']);
            }
            $this->assertParentResource((string) ($prepared['resource_key'] ?? ''), (string) ($prepared['parent_key'] ?? ''));
        }
        if ($resourceKey === 'admin.management_organizations') {
            $this->assertParentNode($resourceKey, (int) ($prepared['parent_id'] ?? 0), 0);
            $this->assertOrganizationContext($prepared);
        }
        if ($resourceKey === 'admin.management_departments') {
            $this->assertParentNode($resourceKey, (int) ($prepared['parent_id'] ?? 0), 0);
            $this->assertDepartmentContext($prepared);
        }
        if ($resourceKey === 'admin.notification_templates'
            && ! $this->database->table('notification_scenes')->where('id', (int) ($prepared['scene_id'] ?? 0))->exists()
        ) {
            throw $this->error('invalid_input', '通知模板所属场景不存在。', ['field' => 'scene_id']);
        }

        return $prepared;
    }

    public function created(string $resourceKey, int $id, array $input, Schema $schema): void
    {
        if ($resourceKey === 'admin.management_users') {
            $this->syncUserRelations($id, $input);
        } elseif ($resourceKey === 'admin.management_roles') {
            $this->syncRolePermissions($id, $input['permissions'] ?? []);
        }
    }

    public function prepareUpdate(string $resourceKey, int $id, array $input, array $prepared, Schema $schema): array
    {
        $this->assertWritable($resourceKey);
        $prepared['updated_at'] = in_array($resourceKey, [
            'admin.management_tenants',
            'admin.management_organizations',
            'admin.management_departments',
            'admin.dashboard_widgets',
            'admin.notification_scenes',
            'admin.notification_templates',
        ], true) ? time() : now();
        if ($resourceKey === 'admin.management_users') {
            $admin = Admin::query()->findOrFail($id);
            if ($admin->is_founder && array_key_exists('status', $input) && !(bool) $input['status']) {
                throw $this->error('founder_protected', '创始人账号不能被禁用。', ['id' => $id]);
            }
            if (array_key_exists('is_founder', $input) && (bool) $input['is_founder'] !== (bool) $admin->is_founder
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
        } elseif ($resourceKey === 'admin.management_roles') {
            $role = AdminRole::query()->findOrFail($id);
            if (array_key_exists('code', $input) && (string) $input['code'] !== (string) $role->code) {
                throw $this->error('role_code_immutable', '角色编码创建后不能修改。', ['id' => $id]);
            }
            if (array_key_exists('tenant_id', $input)
                && $this->nullableId($input['tenant_id']) !== $this->nullableId($role->tenant_id)
            ) {
                throw $this->error('role_tenant_immutable', '角色所属租户创建后不能修改。', ['id' => $id]);
            }
            if ($role->is_system && (
                array_key_exists('is_system', $input) && (bool) $input['is_system'] !== (bool) $role->is_system
                || array_key_exists('status', $input) && !(bool) $input['status']
            )) {
                throw $this->error('system_role_protected', '系统角色标记和状态不能修改。', ['id' => $id]);
            }
            $this->assertRoleScope(array_merge($role->getAttributes(), $prepared), $id);
        } elseif ($resourceKey === 'admin.management_menus') {
            $resource = AdminResource::query()->findOrFail($id);
            if (array_key_exists('resource_key', $input) && (string) $input['resource_key'] !== (string) $resource->resource_key) {
                throw $this->error('resource_key_immutable', '权限资源编码创建后不能修改。', ['id' => $id]);
            }
            $newKey = (string) ($prepared['resource_key'] ?? $resource->resource_key);
            $this->assertParentResource($newKey, (string) ($prepared['parent_key'] ?? $resource->parent_key));
        } elseif ($resourceKey === 'admin.management_organizations') {
            $current = (array) $this->database->table('admin_organizations')->where('id', $id)->first();
            $next = array_merge($current, $prepared);
            $this->assertParentNode($resourceKey, (int) ($next['parent_id'] ?? 0), $id);
            $this->assertOrganizationContext($next);
        } elseif ($resourceKey === 'admin.management_departments') {
            $current = (array) $this->database->table('admin_departments')->where('id', $id)->first();
            $next = array_merge($current, $prepared);
            $this->assertParentNode($resourceKey, (int) ($next['parent_id'] ?? 0), $id);
            $this->assertDepartmentContext($next, $id);
        } elseif ($resourceKey === 'admin.notification_templates'
            && array_key_exists('scene_id', $input)
            && ! $this->database->table('notification_scenes')->where('id', (int) ($prepared['scene_id'] ?? 0))->exists()
        ) {
            throw $this->error('invalid_input', '通知模板所属场景不存在。', ['field' => 'scene_id']);
        }

        return $prepared;
    }

    public function updated(string $resourceKey, int $id, array $input, Schema $schema): void
    {
        if ($resourceKey === 'admin.management_users') {
            $this->syncUserRelations($id, $input);
            if (array_key_exists('status', $input) && !(bool) $input['status']) {
                $this->sessions->revokeSubject(Admin::class, $id, 'admin');
            }
        } elseif ($resourceKey === 'admin.management_roles' && array_key_exists('permissions', $input)) {
            $this->syncRolePermissions($id, $input['permissions']);
        }
    }

    public function deleting(string $resourceKey, int $id, Schema $schema): void
    {
        $this->assertWritable($resourceKey);
        if ($resourceKey === 'admin.management_users') {
            $admin = Admin::query()->findOrFail($id);
            if ($admin->is_founder) {
                throw $this->error('founder_protected', '创始人账号不能删除。', ['id' => $id]);
            }
            $this->sessions->revokeSubject(Admin::class, $id, 'admin');
        } elseif ($resourceKey === 'admin.management_roles') {
            $role = AdminRole::query()->findOrFail($id);
            if ($role->is_system) {
                throw $this->error('system_role_protected', '系统角色不能删除。', ['id' => $id]);
            }
        } elseif ($resourceKey === 'admin.management_menus') {
            $resource = AdminResource::query()->findOrFail($id);
            if ($this->isCoreResource((string) $resource->resource_key)) {
                throw $this->error('core_resource_protected', '核心权限资源不能删除。', ['id' => $id]);
            }
            if (AdminResource::query()->where('parent_key', $resource->resource_key)->exists()) {
                throw $this->error('resource_has_children', '存在子资源时不能删除当前资源。', ['id' => $id]);
            }
        }
    }

    public function deleted(string $resourceKey, int $id, Schema $schema): void
    {
        if ($resourceKey === 'admin.management_users') {
            $this->database->table('admin_user_roles')->where('admin_id', $id)->delete();
            $this->database->table('admin_user_org_relations')->where('user_id', $id)->delete();
            $this->database->table('admin_grants')->where('subject_type', 'admin')->where('subject_id', (string) $id)->delete();
        } elseif ($resourceKey === 'admin.management_roles') {
            $this->database->table('admin_user_roles')->where('role_id', $id)->delete();
            $this->database->table('admin_grants')->where('subject_type', 'role')->where('subject_id', (string) $id)->delete();
        } elseif ($resourceKey === 'admin.management_menus') {
            $this->database->table('admin_grants')->where('resource_id', $id)->delete();
        }
    }

    public function output(string $resourceKey, array $row, Schema $schema): array
    {
        if ($resourceKey === 'admin.management_users') {
            unset($row['password']);
            $row['role_ids'] = $this->database->table('admin_user_roles')->where('admin_id', (int) ($row['id'] ?? 0))->pluck('role_id')->map(static fn ($id): int => (int) $id)->values()->all();
            $row['organization_relations'] = $this->organizationRelationPayload((int) ($row['id'] ?? 0));
            $row['permissions'] = $this->permissionPayload('admin', (string) ($row['id'] ?? 0));
        } elseif ($resourceKey === 'admin.management_roles') {
            $row['permissions'] = $this->permissionPayload('role', (string) ($row['id'] ?? 0));
        }

        return $row;
    }

    public function executeAction(string $resourceKey, string $action, array $input, Schema $schema): array
    {
        $id = (int) ($input['id'] ?? 0);
        if ($resourceKey === 'admin.management_users' && $action === 'sync_roles') {
            $this->admin($id);
            $this->syncUserRelations($id, (array) ($input['data'] ?? []));
            return ['id' => $id, 'role_ids' => $this->database->table('admin_user_roles')->where('admin_id', $id)->pluck('role_id')->map(static fn ($value): int => (int) $value)->values()->all()];
        }
        if ($resourceKey === 'admin.management_users' && $action === 'sync_permissions') {
            $this->admin($id);
            $this->syncAdminPermissions($id, (array) (($input['data'] ?? [])['permissions'] ?? []));
            return ['id' => $id, 'permissions' => $this->permissionPayload('admin', (string) $id)];
        }
        if ($resourceKey === 'admin.management_users' && $action === 'sync_organization_relations') {
            $this->admin($id);
            $this->syncUserRelations($id, [
                'organization_relations' => (array) (($input['data'] ?? [])['organization_relations'] ?? []),
            ]);
            return ['id' => $id, 'organization_relations' => $this->organizationRelationPayload($id)];
        }
        if ($resourceKey === 'admin.management_users' && $action === 'reset_password') {
            $this->admin($id);
            $password = (string) (($input['data'] ?? [])['password'] ?? '');
            if (strlen($password) < 6 || strlen($password) > 4096) throw $this->error('invalid_input', '新密码长度必须为 6 到 4096 个字符。', ['field' => 'password']);
            Admin::query()->whereKey($id)->update(['password' => Hash::make($password), 'updated_at' => now()]);
            $this->sessions->revokeSubject(Admin::class, $id, 'admin');
            return ['id' => $id, 'success' => true];
        }
        if ($resourceKey === 'admin.management_users' && $action === 'revoke_sessions') {
            $this->admin($id);
            return ['id' => $id, 'revoked' => $this->sessions->revokeSubject(Admin::class, $id, 'admin')];
        }
        if ($resourceKey === 'admin.management_roles' && $action === 'sync_permissions') {
            $this->role($id);
            $this->syncRolePermissions($id, (array) (($input['data'] ?? [])['permissions'] ?? []));
            return ['id' => $id, 'permissions' => $this->permissionPayload('role', (string) $id)];
        }

        throw $this->error('action_not_available', 'Admin 动作未实现。', ['resource_key' => $resourceKey, 'action' => $action]);
    }

    private function syncUserRelations(int $adminId, array $input): void
    {
        $hasRoles = array_key_exists('role_ids', $input);
        $hasOrganizations = array_key_exists('organization_relations', $input);
        $roleIds = $hasRoles
            ? array_values(array_unique(array_filter(array_map('intval', (array) $input['role_ids']), static fn (int $id): bool => $id > 0)))
            : [];
        $roles = [];
        if ($hasRoles) {
            $roles = $this->database->table('admin_roles')->whereIn('id', $roleIds)->where('status', 1)->whereNull('deleted_at')->get(['id', 'tenant_id'])->all();
        } elseif ($hasOrganizations) {
            $roles = $this->database->table('admin_user_roles as relations')
                ->join('admin_roles as roles', 'roles.id', '=', 'relations.role_id')
                ->where('relations.admin_id', $adminId)
                ->where('roles.status', 1)
                ->whereNull('roles.deleted_at')
                ->get(['roles.id', 'roles.tenant_id'])
                ->all();
        }
        $validRoleIds = array_map(static fn (object $role): int => (int) $role->id, $roles);
        sort($roleIds);
        sort($validRoleIds);
        if ($hasRoles && $roleIds !== $validRoleIds) {
            throw $this->error('invalid_role_selection', '角色选择包含不存在或已停用的角色。', ['role_ids' => $roleIds]);
        }

        $organizationRelations = $hasOrganizations
            ? $this->normalizeOrganizationRelations((array) $input['organization_relations'])
            : $this->organizationRelationPayload($adminId);
        $relationTenantIds = array_values(array_unique(array_map(
            static fn (array $relation): ?int => $relation['tenant_id'],
            $organizationRelations,
        )));
        foreach ($roles as $role) {
            $tenantId = $role->tenant_id === null ? null : (int) $role->tenant_id;
            if ($tenantId !== null && !in_array($tenantId, $relationTenantIds, true)) {
                throw $this->error('invalid_role_selection', '租户角色只能分配给同一租户下已有组织归属的管理员。', [
                    'role_id' => (int) $role->id,
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        if ($hasRoles || $hasOrganizations) {
            $this->database->transaction(function () use ($adminId, $hasRoles, $hasOrganizations, $roles, $organizationRelations): void {
                if ($hasOrganizations) {
                    $this->database->table('admin_user_org_relations')->where('user_id', $adminId)->delete();
                    foreach ($organizationRelations as $relation) {
                        $this->database->table('admin_user_org_relations')->insert($relation + [
                            'user_id' => $adminId,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);
                    }
                }
                if ($hasRoles) {
                    $this->database->table('admin_user_roles')->where('admin_id', $adminId)->delete();
                    foreach ($roles as $role) {
                        $this->database->table('admin_user_roles')->insert([
                            'admin_id' => $adminId,
                            'role_id' => (int) $role->id,
                            'tenant_id' => $role->tenant_id === null ? null : (int) $role->tenant_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
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
        $roleTenantId = null;
        if ($subjectType === 'role') {
            $role = $this->database->table('admin_roles')->where('id', (int) $subjectId)->first(['tenant_id']);
            $roleTenantId = $role?->tenant_id === null ? null : (int) $role->tenant_id;
        }
        foreach ($permissions as $permission) {
            if (!is_array($permission)) {
                throw $this->error('invalid_permission', '权限项必须是对象。');
            }
            $resourceKey = (string) ($permission['resource_key'] ?? '');
            $resourceId = (int) $this->database->table('admin_resources')->where('resource_key', $resourceKey)->value('id');
            if ($resourceId <= 0) {
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
            $tenantId = $this->nullableId($permission['tenant_id'] ?? $roleTenantId);
            if ($roleTenantId !== null && $tenantId !== $roleTenantId) {
                throw $this->error('invalid_permission', '租户角色的授权必须属于角色所在租户。', ['resource_key' => $resourceKey]);
            }
            $this->assertTenantExists($tenantId);
            if ($subjectType === 'admin' && $tenantId !== null
                && !$this->database->table('admin_user_org_relations')->where('user_id', (int) $subjectId)->where('tenant_id', $tenantId)->exists()
            ) {
                throw $this->error('invalid_permission', '租户授权只能分配给该租户下已有组织归属的管理员。', ['tenant_id' => $tenantId]);
            }
            $scopeType = $this->nullableString($permission['scope_type'] ?? null);
            if ($scopeType !== null && !in_array($scopeType, ['all', 'self', 'tenant', 'organization', 'department', 'assigned'], true)) {
                throw $this->error('invalid_permission', '权限数据范围类型不合法。', ['scope_type' => $scopeType]);
            }
            $scopeValue = $permission['scope_value'] ?? null;
            if ($scopeValue !== null && !is_array($scopeValue)) {
                throw $this->error('invalid_permission', '权限数据范围参数必须是对象或数组。', ['resource_key' => $resourceKey]);
            }
            $conditions = $permission['conditions'] ?? null;
            if ($conditions !== null && (!is_array($conditions) || !$this->validConditions($conditions))) {
                throw $this->error('invalid_permission', '权限附加条件格式不合法。', ['resource_key' => $resourceKey]);
            }
            $this->assertPermissionScope($scopeType, $scopeValue, $tenantId);
            $expiresAt = $this->normalizeExpiresAt($permission['expires_at'] ?? null);
            $priority = (int) ($permission['priority'] ?? ($subjectType === 'admin' ? 100 : 0));
            if ($priority < 0 || $priority > 10000) {
                throw $this->error('invalid_permission', '权限优先级必须在 0 到 10000 之间。', ['priority' => $priority]);
            }
            $normalized[] = [
                'resource_id' => $resourceId,
                'effect' => $effect,
                'abilities' => $abilities,
                'priority' => $priority,
                'tenant_id' => $tenantId,
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
                    'tenant_id' => $permission['tenant_id'],
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
        return $this->database->table('admin_grants as grants')->join('admin_resources as resources', 'resources.id', '=', 'grants.resource_id')->whereNull('resources.deleted_at')->where('grants.subject_type', $subjectType)->where('grants.subject_id', $subjectId)->orderBy('grants.id')->get(['resources.resource_key', 'grants.effect', 'grants.abilities_json', 'grants.priority', 'grants.tenant_id', 'grants.scope_type', 'grants.scope_value_json', 'grants.conditions_json', 'grants.expires_at'])->map(static function ($grant): array {
            return [
                'resource_key' => (string) $grant->resource_key,
                'effect' => (string) $grant->effect,
                'abilities' => (array) json_decode((string) $grant->abilities_json, true),
                'priority' => (int) $grant->priority,
                'tenant_id' => $grant->tenant_id === null ? null : (int) $grant->tenant_id,
                'scope_type' => $grant->scope_type === null ? null : (string) $grant->scope_type,
                'scope_value' => $grant->scope_value_json === null ? null : json_decode((string) $grant->scope_value_json, true),
                'conditions' => $grant->conditions_json === null ? null : json_decode((string) $grant->conditions_json, true),
                'expires_at' => $grant->expires_at === null ? null : (string) $grant->expires_at,
            ];
        })->all();
    }

    /** @return list<array{tenant_id:?int,organization_id:int,department_id:?int,is_primary:bool}> */
    private function normalizeOrganizationRelations(array $relations): array
    {
        $normalized = [];
        $primaryTenants = [];
        $seen = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                throw $this->error('invalid_organization_relation', '组织归属项必须是对象。');
            }
            $organizationId = (int) ($relation['organization_id'] ?? 0);
            $organization = $this->database->table('admin_organizations')
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->where('id', $organizationId)
                ->first(['id', 'tenant_id']);
            if (!$organization) {
                throw $this->error('invalid_organization_relation', '组织归属中的组织不存在或已停用。', ['organization_id' => $organizationId]);
            }
            $organizationTenantId = $this->nullableId($organization->tenant_id ?? null);
            $tenantId = array_key_exists('tenant_id', $relation)
                ? $this->nullableId($relation['tenant_id'])
                : $organizationTenantId;
            if ($tenantId !== $organizationTenantId) {
                throw $this->error('invalid_organization_relation', '组织归属的租户与组织不一致。', ['organization_id' => $organizationId]);
            }
            $departmentId = $this->nullableId($relation['department_id'] ?? null);
            if ($departmentId !== null) {
                $department = $this->database->table('admin_departments')
                    ->whereNull('deleted_at')
                    ->where('status', 1)
                    ->where('id', $departmentId)
                    ->first(['organization_id', 'tenant_id']);
                if (!$department
                    || (int) $department->organization_id !== $organizationId
                    || $this->nullableId($department->tenant_id ?? null) !== $tenantId
                ) {
                    throw $this->error('invalid_organization_relation', '部门必须属于当前组织和租户。', ['department_id' => $departmentId]);
                }
            }
            $isPrimary = (bool) ($relation['is_primary'] ?? false);
            $tenantKey = $tenantId === null ? 'global' : (string) $tenantId;
            if ($isPrimary && isset($primaryTenants[$tenantKey])) {
                throw $this->error('invalid_organization_relation', '同一租户只能设置一个主要组织归属。', ['tenant_id' => $tenantId]);
            }
            $key = $tenantKey.':'.$organizationId.':'.($departmentId ?? 0);
            if (isset($seen[$key])) {
                throw $this->error('invalid_organization_relation', '组织归属不能重复。', ['organization_id' => $organizationId, 'department_id' => $departmentId]);
            }
            $seen[$key] = true;
            if ($isPrimary) {
                $primaryTenants[$tenantKey] = true;
            }
            $normalized[] = [
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'department_id' => $departmentId,
                'is_primary' => $isPrimary,
            ];
        }

        return $normalized;
    }

    /** @return list<array{tenant_id:?int,organization_id:int,department_id:?int,is_primary:bool}> */
    private function organizationRelationPayload(int $adminId): array
    {
        return $this->database->table('admin_user_org_relations')
            ->where('user_id', $adminId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['tenant_id', 'organization_id', 'department_id', 'is_primary'])
            ->map(fn (object $relation): array => [
                'tenant_id' => $this->nullableId($relation->tenant_id ?? null),
                'organization_id' => (int) $relation->organization_id,
                'department_id' => $this->nullableId($relation->department_id ?? null),
                'is_primary' => (bool) $relation->is_primary,
            ])
            ->all();
    }

    /** @param array<string, mixed> $role */
    private function assertRoleScope(array $role, int $roleId = 0): void
    {
        $tenantId = $this->nullableId($role['tenant_id'] ?? null);
        $this->assertTenantExists($tenantId);
        $scopeType = $this->nullableString($role['scope_mode'] ?? null) ?? 'all';
        if (!in_array($scopeType, ['all', 'self', 'tenant', 'organization', 'department', 'assigned'], true)) {
            throw $this->error('invalid_role_scope', '角色默认数据范围类型不合法。', ['scope_mode' => $scopeType]);
        }
        $scopeValue = $role['scope_value_json'] ?? null;
        if (is_string($scopeValue) && $scopeValue !== '') {
            $scopeValue = json_decode($scopeValue, true);
        }
        if ($scopeValue !== null && !is_array($scopeValue)) {
            throw $this->error('invalid_role_scope', '角色默认范围参数必须是对象或数组。');
        }
        $this->assertPermissionScope($scopeType, $scopeValue, $tenantId);

        if ($roleId <= 0 || $tenantId === null) {
            return;
        }
        $adminIds = $this->database->table('admin_user_roles')->where('role_id', $roleId)->pluck('admin_id');
        foreach ($adminIds as $adminId) {
            if (!$this->database->table('admin_user_org_relations')->where('user_id', (int) $adminId)->where('tenant_id', $tenantId)->exists()) {
                throw $this->error('invalid_role_scope', '已有管理员不属于角色目标租户，不能修改角色租户。', [
                    'role_id' => $roleId,
                    'admin_id' => (int) $adminId,
                    'tenant_id' => $tenantId,
                ]);
            }
        }
    }

    /** @param array<string, mixed>|list<mixed>|null $scopeValue */
    private function assertPermissionScope(?string $scopeType, ?array $scopeValue, ?int $tenantId): void
    {
        if ($scopeType === null || in_array($scopeType, ['all', 'self', 'assigned'], true)) {
            return;
        }
        if ($scopeType === 'tenant') {
            foreach ($this->scopeIds($scopeValue, ['tenant_id', 'tenant_ids', 'id', 'ids']) as $id) {
                $this->assertTenantExists($id);
                if ($tenantId !== null && $id !== $tenantId) {
                    throw $this->error('invalid_permission', '授权范围中的租户必须与授权租户一致。', ['tenant_id' => $id]);
                }
            }

            return;
        }
        $field = $scopeType === 'organization' ? 'organization_id' : 'department_id';
        $table = $scopeType === 'organization' ? 'admin_organizations' : 'admin_departments';
        $keys = [$field, $field.'s', 'id', 'ids'];
        foreach ($this->scopeIds($scopeValue, $keys) as $id) {
            $record = $this->database->table($table)->whereNull('deleted_at')->where('id', $id)->first(['tenant_id']);
            if (!$record || ($tenantId !== null && $this->nullableId($record->tenant_id ?? null) !== $tenantId)) {
                throw $this->error('invalid_permission', '授权范围引用的组织或部门不存在，或不属于授权租户。', [
                    'scope_type' => $scopeType,
                    'id' => $id,
                ]);
            }
        }
    }

    private function assertTenantExists(?int $tenantId): void
    {
        if ($tenantId !== null && !$this->database->table('admin_tenants')->whereNull('deleted_at')->where('status', 1)->where('id', $tenantId)->exists()) {
            throw $this->error('invalid_tenant', '租户不存在或已停用。', ['tenant_id' => $tenantId]);
        }
    }

    /** @param array<string, mixed>|list<mixed>|null $value @param list<string> $keys @return list<int> */
    private function scopeIds(?array $value, array $keys): array
    {
        if ($value === null) {
            return [];
        }
        $ids = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            foreach ((array) $value[$key] as $candidate) {
                if ((is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) && (int) $candidate > 0) {
                    $ids[] = (int) $candidate;
                }
            }
        }
        if ($ids === [] && array_is_list($value)) {
            foreach ($value as $candidate) {
                if ((is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) && (int) $candidate > 0) {
                    $ids[] = (int) $candidate;
                }
            }
        }

        return array_values(array_unique($ids));
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

        return in_array((string) ($conditions['field'] ?? ''), ['admin_id', 'tenant_id', 'organization_id', 'department_id', 'ability'], true)
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

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    private function error(string $code, string $message, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details);
    }

    private function isCoreResource(string $resourceKey): bool
    {
        return in_array($resourceKey, [
            'dashboard', 'admin.users', 'admin.roles', 'admin.menus', 'admin.logs', 'admin.tenants',
            'admin.organizations', 'admin.departments', 'admin.notifications', 'admin.notification_templates',
            'admin.management_users', 'admin.management_roles', 'admin.management_menus',
            'admin.management_tenants', 'admin.management_organizations', 'admin.management_departments',
            'admin.dashboard_widgets', 'admin.notification_scenes', 'admin.notification_messages',
            'admin.notification_receipts', 'admin.notification_deliveries',
            'schema.resources', 'admin.config', 'tasks', 'core.tasks', 'core.update',
            'admin.frontend', 'addon.manage', 'addon.download', 'addon.install',
            'addon.update', 'addon.uninstall', 'addon.frontend', 'addon.enable', 'addon.disable',
        ], true);
    }

    private function assertWritable(string $resourceKey): void
    {
        if (in_array($resourceKey, [
            'admin.login_logs',
            'admin.operation_records',
            'admin.notification_messages',
            'admin.notification_receipts',
            'admin.notification_deliveries',
        ], true)) {
            throw $this->error('read_only_resource', '审计资源只允许查询。', ['resource_key' => $resourceKey]);
        }
    }

    private function admin(int $id): Admin
    {
        $admin = Admin::query()->find($id);
        if (!$admin) throw $this->error('record_not_found', '管理员不存在。', ['id' => $id]);

        return $admin;
    }

    private function role(int $id): AdminRole
    {
        $role = AdminRole::query()->find($id);
        if (!$role) throw $this->error('record_not_found', '角色不存在。', ['id' => $id]);

        return $role;
    }

    private function assertParentResource(string $resourceKey, string $parentKey): void
    {
        if ($parentKey === '') return;
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

    private function assertParentNode(string $resourceKey, int $parentId, int $currentId): void
    {
        if ($parentId === 0) {
            return;
        }
        $table = $resourceKey === 'admin.management_organizations'
            ? 'admin_organizations'
            : 'admin_departments';
        if ($parentId === $currentId || ! $this->database->table($table)->whereNull('deleted_at')->where('id', $parentId)->exists()) {
            throw $this->error('invalid_input', '树节点父级不存在或不能指向自身。', ['field' => 'parent_id']);
        }

        $seen = [$currentId => true];
        while ($parentId !== 0) {
            if (isset($seen[$parentId])) {
                throw $this->error('invalid_input', '树节点父级关系形成循环。', ['field' => 'parent_id']);
            }
            $seen[$parentId] = true;
            $parentId = (int) ($this->database->table($table)->where('id', $parentId)->value('parent_id') ?? 0);
        }
    }

    /** @param array<string, mixed> $organization */
    private function assertOrganizationContext(array $organization): void
    {
        $parentId = (int) ($organization['parent_id'] ?? 0);
        if ($parentId === 0) {
            return;
        }
        $parent = $this->database->table('admin_organizations')->whereNull('deleted_at')->where('id', $parentId)->first();
        if (!$parent || ($parent->tenant_id ?? null) != ($organization['tenant_id'] ?? null)) {
            throw $this->error('invalid_input', '父组织必须属于同一租户。', ['field' => 'parent_id']);
        }
    }

    /** @param array<string, mixed> $department */
    private function assertDepartmentContext(array $department, int $currentId = 0): void
    {
        $organizationId = (int) ($department['organization_id'] ?? 0);
        $organization = $this->database->table('admin_organizations')
            ->whereNull('deleted_at')
            ->where('id', $organizationId)
            ->first();
        if (!$organization) {
            throw $this->error('invalid_input', '部门所属组织不存在。', ['field' => 'organization_id']);
        }
        if (($organization->tenant_id ?? null) != ($department['tenant_id'] ?? null)) {
            throw $this->error('invalid_input', '部门与所属组织必须位于同一租户。', ['field' => 'tenant_id']);
        }

        $parentId = (int) ($department['parent_id'] ?? 0);
        if ($parentId === 0) {
            return;
        }
        $parent = $this->database->table('admin_departments')
            ->whereNull('deleted_at')
            ->where('id', $parentId)
            ->when($currentId > 0, static fn ($query) => $query->where('id', '!=', $currentId))
            ->first();
        if (!$parent || (int) $parent->organization_id !== $organizationId) {
            throw $this->error('invalid_input', '父部门必须属于同一组织。', ['field' => 'parent_id']);
        }
    }
}
