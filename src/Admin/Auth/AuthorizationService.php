<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Models\Admin;

final class AuthorizationService
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function allows(
        Admin $admin,
        string $ability,
        string $resourceKey,
        ?AuthorizationContext $context = null,
    ): bool {
        return $this->decision($admin, $ability, $resourceKey, $context)->allowed;
    }

    public function decision(
        Admin $admin,
        string $ability,
        string $resourceKey,
        ?AuthorizationContext $context = null,
    ): AuthorizationDecision {
        if ((bool) $admin->is_founder) {
            return new AuthorizationDecision(true, 'founder');
        }

        $resource = $this->database->table('admin_resources')
            ->whereNull('deleted_at')
            ->where('resource_key', $resourceKey)
            ->first();
        if (null === $resource || (int) $resource->status !== 1) {
            return new AuthorizationDecision(false, 'resource_unavailable');
        }

        $relations = $this->organizationRelations((int) $admin->getKey());
        $direct = $this->grantsForSubject(
            'admin',
            (string) $admin->getKey(),
            (int) $resource->id,
            $ability,
            $admin,
            $context,
            $relations,
        );
        if ($direct !== []) {
            return $this->resolveGrants($direct, 'direct_grant');
        }

        $roleGrants = [];
        foreach ($this->roleAssignments((int) $admin->getKey(), $context) as $assignment) {
            foreach ($this->grantsForSubject(
                'role',
                (string) $assignment['role_id'],
                (int) $resource->id,
                $ability,
                $admin,
                $context,
                $relations,
                $assignment,
            ) as $grant) {
                $roleGrants[] = $grant;
            }
        }

        return $roleGrants === []
            ? new AuthorizationDecision(false, 'grant_not_found')
            : $this->resolveGrants($roleGrants, 'role_grant');
    }

    /** @return array<int, string> */
    public function permissions(Admin $admin): array
    {
        if ((bool) $admin->is_founder) {
            return ['*'];
        }

        $resources = $this->database->table('admin_resources')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $abilities = $this->declaredAbilities($admin);
        $permissions = [];
        foreach ($resources as $resource) {
            foreach ($abilities[(int) $resource->id] ?? [] as $ability) {
                if ($this->allows($admin, $ability, (string) $resource->resource_key)) {
                    $permissions[] = (string) $resource->resource_key.'.'.$ability;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    /** @return array<int, array<string, mixed>> */
    public function menus(Admin $admin): array
    {
        $resources = $this->database->table('admin_resources')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->where('is_menu', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $menus = [];
        foreach ($resources as $resource) {
            if (!$this->allows($admin, 'view', (string) $resource->resource_key)) {
                continue;
            }

            $meta = $this->decode((string) ($resource->meta_json ?? ''));
            $pageType = $meta['page_type'] ?? null;
            if (!is_string($pageType) || !in_array($pageType, [
                'dashboard',
                'schema.resource',
                'logs.center',
                'task.center',
                'plugin.page',
                'cloud.market',
                'external.link',
            ], true)) {
                continue;
            }

            $menu = [
                'key' => (string) $resource->resource_key,
                'title' => (string) $resource->title,
                'path' => $this->menuPath((string) ($resource->path ?? '')),
                'page_type' => $pageType,
                'children' => [],
            ];
            foreach (['resource_key', 'icon'] as $field) {
                if (is_string($meta[$field] ?? null) && $meta[$field] !== '') {
                    $menu[$field] = $meta[$field];
                }
            }
            $menus[] = [
                'node' => $menu,
                'parent_key' => (string) ($resource->parent_key ?? ''),
            ];
        }

        return $this->menuTree($menus);
    }

    /**
     * @param list<object> $relations
     * @param array<string, mixed>|null $role
     * @return list<AuthorizationGrant>
     */
    private function grantsForSubject(
        string $subjectType,
        string $subjectId,
        int $resourceId,
        string $ability,
        Admin $admin,
        ?AuthorizationContext $context,
        array $relations,
        ?array $role = null,
    ): array {
        $rows = $this->database->table('admin_grants')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('resource_id', $resourceId)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        $grants = [];
        foreach ($rows as $row) {
            $abilities = $this->decodeList((string) $row->abilities_json);
            if (!in_array($ability, $abilities, true) && !in_array('*', $abilities, true)) {
                continue;
            }

            $grantTenantId = $this->nullableId($row->tenant_id ?? null)
                ?? $this->nullableId($role['tenant_id'] ?? null);
            if ($context?->tenantId !== null && $grantTenantId !== null && $grantTenantId !== $context->tenantId) {
                continue;
            }

            $conditions = $this->decodeNullable((string) ($row->conditions_json ?? ''));
            if (!$this->conditionsMatch($conditions, $admin, $ability, $grantTenantId, $context, $relations)) {
                continue;
            }

            $scopeType = $this->nullableString($row->scope_type ?? null)
                ?? $this->nullableString($role['scope_mode'] ?? null);
            $scopeValue = $this->decodeNullable((string) ($row->scope_value_json ?? ''))
                ?? $this->decodeNullable((string) ($role['scope_value_json'] ?? ''));
            $grants[] = new AuthorizationGrant(
                (int) $row->id,
                $subjectType,
                $subjectId,
                (string) $row->effect === 'allow' ? 'allow' : 'deny',
                $abilities,
                (int) $row->priority,
                $grantTenantId,
                $scopeType,
                $scopeValue,
                $conditions,
            );
        }

        return $grants;
    }

    /** @param list<AuthorizationGrant> $grants */
    private function resolveGrants(array $grants, string $reason): AuthorizationDecision
    {
        usort($grants, static function (AuthorizationGrant $left, AuthorizationGrant $right): int {
            if ($left->priority !== $right->priority) {
                return $right->priority <=> $left->priority;
            }
            if ($left->effect !== $right->effect) {
                return $left->effect === 'deny' ? -1 : 1;
            }

            return $right->id <=> $left->id;
        });

        $priority = $grants[0]->priority;
        $selected = array_values(array_filter(
            $grants,
            static fn (AuthorizationGrant $grant): bool => $grant->priority === $priority,
        ));
        foreach ($selected as $grant) {
            if ($grant->effect === 'deny') {
                return new AuthorizationDecision(false, $reason.'_deny', [$grant]);
            }
        }

        return new AuthorizationDecision(true, $reason.'_allow', $selected);
    }

    /** @return list<array{role_id:int,tenant_id:?int,scope_mode:?string,scope_value_json:?string}> */
    private function roleAssignments(int $adminId, ?AuthorizationContext $context): array
    {
        $rows = $this->database->table('admin_user_roles as relations')
            ->join('admin_roles as roles', 'roles.id', '=', 'relations.role_id')
            ->where('relations.admin_id', $adminId)
            ->where('roles.status', 1)
            ->whereNull('roles.deleted_at')
            ->get([
                'relations.role_id',
                'relations.tenant_id as relation_tenant_id',
                'roles.tenant_id as role_tenant_id',
                'roles.scope_mode',
                'roles.scope_value_json',
            ]);

        $assignments = [];
        foreach ($rows as $row) {
            $relationTenant = $this->nullableId($row->relation_tenant_id ?? null);
            $roleTenant = $this->nullableId($row->role_tenant_id ?? null);
            if ($roleTenant !== null && $relationTenant !== $roleTenant) {
                continue;
            }
            $tenantId = $roleTenant ?? $relationTenant;
            if ($context?->tenantId !== null && $tenantId !== null && $tenantId !== $context->tenantId) {
                continue;
            }
            $assignments[] = [
                'role_id' => (int) $row->role_id,
                'tenant_id' => $tenantId,
                'scope_mode' => $this->nullableString($row->scope_mode ?? null),
                'scope_value_json' => $this->nullableString($row->scope_value_json ?? null),
            ];
        }

        return $assignments;
    }

    /** @return list<object> */
    private function organizationRelations(int $adminId): array
    {
        return $this->database->table('admin_user_org_relations')
            ->where('user_id', $adminId)
            ->get()
            ->all();
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $conditions
     * @param list<object> $relations
     */
    private function conditionsMatch(
        ?array $conditions,
        Admin $admin,
        string $ability,
        ?int $grantTenantId,
        ?AuthorizationContext $context,
        array $relations,
    ): bool {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        $tenantId = $context?->tenantId ?? $grantTenantId;
        $matchingRelations = array_values(array_filter(
            $relations,
            static fn (object $relation): bool => $tenantId === null || (int) ($relation->tenant_id ?? 0) === $tenantId,
        ));
        $values = [
            'admin_id' => (int) $admin->getKey(),
            'tenant_id' => $tenantId,
            'organization_id' => $context?->organizationId
                ?? array_values(array_unique(array_map(static fn (object $relation): int => (int) $relation->organization_id, $matchingRelations))),
            'department_id' => $context?->departmentId
                ?? array_values(array_unique(array_filter(array_map(static fn (object $relation): ?int => isset($relation->department_id) ? (int) $relation->department_id : null, $matchingRelations)))),
            'ability' => $ability,
        ] + ($context?->attributes ?? []);

        return $this->evaluateCondition($conditions, $values);
    }

    /** @param array<string, mixed>|list<mixed> $condition @param array<string, mixed> $values */
    private function evaluateCondition(array $condition, array $values): bool
    {
        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $child) {
                if (!is_array($child) || !$this->evaluateCondition($child, $values)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $child) {
                if (is_array($child) && $this->evaluateCondition($child, $values)) {
                    return true;
                }
            }

            return false;
        }

        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'eq');
        if (!in_array($field, ['admin_id', 'tenant_id', 'organization_id', 'department_id', 'ability'], true)
            || !in_array($operator, ['eq', 'neq', 'in', 'not_in'], true)
        ) {
            return false;
        }
        $actual = $values[$field] ?? null;
        $expected = $condition['value'] ?? null;
        $actualValues = is_array($actual) ? $actual : [$actual];
        $expectedValues = is_array($expected) ? $expected : [$expected];
        $matched = array_intersect($actualValues, $expectedValues) !== [];

        return in_array($operator, ['neq', 'not_in'], true) ? !$matched : $matched;
    }

    /** @return array<int, list<string>> */
    private function declaredAbilities(Admin $admin): array
    {
        $roleIds = array_column($this->roleAssignments((int) $admin->getKey(), null), 'role_id');
        $rows = $this->database->table('admin_grants')
            ->where(function ($query) use ($admin, $roleIds): void {
                $query->where(function ($direct) use ($admin): void {
                    $direct->where('subject_type', 'admin')->where('subject_id', (string) $admin->getKey());
                });
                if ($roleIds !== []) {
                    $query->orWhere(function ($roles) use ($roleIds): void {
                        $roles->where('subject_type', 'role')->whereIn('subject_id', array_map('strval', $roleIds));
                    });
                }
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get(['resource_id', 'abilities_json']);

        $abilities = [];
        foreach ($rows as $row) {
            foreach ($this->decodeList((string) $row->abilities_json) as $ability) {
                $abilities[(int) $row->resource_id][] = $ability;
            }
        }
        foreach ($abilities as $resourceId => $resourceAbilities) {
            $abilities[$resourceId] = array_values(array_unique($resourceAbilities));
        }

        return $abilities;
    }

    /**
     * @param array<int, array{node:array<string,mixed>,parent_key:string}> $menus
     * @return array<int, array<string, mixed>>
     */
    private function menuTree(array $menus): array
    {
        $nodes = [];
        $parents = [];
        $order = [];
        foreach ($menus as $menu) {
            $key = (string) $menu['node']['key'];
            $nodes[$key] = $menu['node'];
            $parents[$key] = $menu['parent_key'];
            $order[] = $key;
        }

        $rootKeys = [];
        $children = [];
        foreach ($order as $key) {
            $parentKey = $parents[$key];
            if ($parentKey === '' || !isset($nodes[$parentKey]) || !$this->canAttach($key, $parentKey, $parents)) {
                $rootKeys[] = $key;
                continue;
            }
            $children[$parentKey][] = $key;
        }

        $roots = [];
        foreach ($rootKeys as $key) {
            $roots[] = $this->buildMenuNode($key, $nodes, $children);
        }

        return $roots;
    }

    /** @param array<string, array<string, mixed>> $nodes @param array<string, array<int, string>> $children */
    private function buildMenuNode(string $key, array $nodes, array $children): array
    {
        $node = $nodes[$key];
        $nested = [];
        foreach ($children[$key] ?? [] as $childKey) {
            $nested[] = $this->buildMenuNode($childKey, $nodes, $children);
        }
        if ($nested === []) {
            unset($node['children']);
        } else {
            $node['children'] = $nested;
        }

        return $node;
    }

    /** @param array<string, string> $parents */
    private function canAttach(string $key, string $parentKey, array $parents): bool
    {
        $seen = [$key => true];
        while ($parentKey !== '') {
            if (isset($seen[$parentKey])) {
                return false;
            }
            $seen[$parentKey] = true;
            $parentKey = $parents[$parentKey] ?? '';
        }

        return true;
    }

    private function menuPath(string $path): string
    {
        $path = trim($path);

        return $path === '' || $path === '/' ? '/' : '/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function decodeList(string $value): array
    {
        return array_values(array_filter(
            array_map('strval', $this->decode($value)),
            static fn (string $ability): bool => $ability !== '',
        ));
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function decodeNullable(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }
}
