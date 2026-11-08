<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Models\Admin;

final class AuthorizationService
{
    private const ACCESS_ABILITY = 'access';

    private const LEGACY_MENU_ACCESS_ABILITY = 'view';

    private readonly AuthorizationIdentityResolverInterface $identities;

    public function __construct(private ConnectionInterface $database, ?AuthorizationIdentityResolverInterface $identities = null)
    {
        $this->identities = $identities ?? new AccountAuthorizationIdentityResolver($database);
    }

    public function identity(Admin $admin): AuthorizationIdentity
    {
        return $this->identities->resolve($admin);
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
        if (!$this->identities->permits($admin, $resourceKey, $ability)) {
            return new AuthorizationDecision(false, 'identity_restricted');
        }
        return $this->evaluate($admin, $ability, $resourceKey, $this->resources(), $this->grantRows($admin), $context);
    }

    /** @param list<object> $resources @param list<object> $rows */
    private function evaluate(Admin $admin, string $ability, string $resourceKey, array $resources, array $rows, ?AuthorizationContext $context = null): AuthorizationDecision
    {
        $resourceKeys = [$resourceKey];
        foreach ($resources as $resource) {
            if ((string) $resource->resource_key === $resourceKey) {
                $meta = $this->decode((string) ($resource->meta_json ?? ''));
                $target = (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '');
                if ($target !== '') {
                    $resourceKeys[] = $target;
                }
            }
        }
        $resourceIds = [];
        foreach ($resources as $resource) {
            $meta = $this->decode((string) ($resource->meta_json ?? ''));
            if (in_array((string) $resource->resource_key, $resourceKeys, true)
                || in_array((string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? ''), $resourceKeys, true)) {
                $resourceIds[] = (int) $resource->id;
            }
        }
        if ($resourceIds === []) {
            return new AuthorizationDecision(false, 'resource_unavailable');
        }
        $grants = $this->matchingGrants($rows, $resourceIds, $ability, $admin, $context);
        return $grants === []
            ? new AuthorizationDecision(false, 'grant_not_found')
            : $this->resolveGrants($grants);
    }

    /** @return array<int, string> */
    public function permissions(Admin $admin): array
    {
        $resources = $this->resources();
        $rows = $this->grantRows($admin);
        $abilities = [];
        foreach ($rows as $row) {
            $abilities[(int) $row->resource_id] = array_merge($abilities[(int) $row->resource_id] ?? [], $this->decodeList((string) $row->abilities_json));
        }
        $permissions = [];
        foreach ($resources as $resource) {
            $candidates = array_values(array_unique(array_merge(
                $abilities[(int) $resource->id] ?? [],
                $this->resourceAbilities($resource),
                [self::ACCESS_ABILITY],
            )));
            foreach ($candidates as $ability) {
                if ($ability !== '*' && $this->identities->permits($admin, (string) $resource->resource_key, $ability) && $this->evaluate($admin, $ability, (string) $resource->resource_key, $resources, $rows)->allowed) {
                    $permissions[] = (string) $resource->resource_key.'.'.$ability;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    /** @return list<string> */
    public function roles(Admin $admin): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $code): string => (string) $code,
            $this->database->table('admin_roles as roles')
                ->whereIn('roles.id', $this->identity($admin)->roleIds)
                ->where('roles.status', 1)
                ->whereNull('roles.deleted_at')
                ->pluck('roles.code')
                ->all(),
        )));
    }

    /** @return array<int, array<string, mixed>> */
    public function menus(Admin $admin): array
    {
        $resources = $this->resources();
        $rows = $this->grantRows($admin);
        $available = [];
        $allowed = [];
        foreach ($resources as $resource) {
            if (!(bool) $resource->is_menu) {
                continue;
            }
            $meta = $this->decode((string) ($resource->meta_json ?? ''));
            $type = (string) ($resource->type ?? '');
            if (!in_array($type, ['group', 'schema', 'page', 'external'], true)) {
                continue;
            }

            $path = (string) ($resource->path ?? '');

            $menu = [
                'key' => (string) $resource->resource_key,
                'title' => (string) $resource->title,
                'type' => $type,
                'path' => $this->menuPath($path),
                'children' => [],
            ];
            $targetResourceKey = (string) ($meta['target_resource_key'] ?? $meta['resource_key'] ?? '');
            $icon = (string) ($resource->icon ?? $meta['icon'] ?? '');
            if ($targetResourceKey !== '') {
                $menu['resource_key'] = $targetResourceKey;
                $menu['target_resource_key'] = $targetResourceKey;
            }
            $ownerType = (string) ($meta['owner_type'] ?? 'unknown');
            $ownerCode = (string) ($meta['owner_code'] ?? '');
            $menu['owner_type'] = $ownerType;
            if ($ownerCode !== '') {
                $menu['owner_code'] = $ownerCode;
            }
            if ($icon !== '') {
                $menu['icon'] = $icon;
            }
            $externalUrl = (string) ($meta['external_url'] ?? '');
            if ($externalUrl !== '') {
                $menu['external_url'] = $externalUrl;
            }
            $available[(string) $resource->resource_key] = [
                'node' => $menu,
                'parent_key' => (string) ($resource->parent_key ?? ''),
            ];
            if ($this->identities->permits($admin, (string) $resource->resource_key, self::ACCESS_ABILITY)
                && $this->evaluate($admin, self::ACCESS_ABILITY, (string) $resource->resource_key, $resources, $rows)->allowed) {
                $allowed[(string) $resource->resource_key] = true;
            }
        }

        $included = $allowed;
        foreach (array_keys($allowed) as $key) {
            $parentKey = (string) ($available[$key]['parent_key'] ?? '');
            $seen = [$key => true];
            while ($parentKey !== '' && isset($available[$parentKey]) && !isset($seen[$parentKey])) {
                $included[$parentKey] = true;
                $seen[$parentKey] = true;
                $parentKey = (string) $available[$parentKey]['parent_key'];
            }
        }
        $menus = array_values(array_intersect_key($available, $included));

        return $this->menuTree($menus);
    }

    /** @return list<object> */
    private function resources(): array
    {
        return $this->database->table('admin_resources')
            ->whereNull('deleted_at')->where('status', 1)->orderBy('sort')->orderBy('id')->get()->all();
    }

    /** @param list<object> $rows @param list<int> $resourceIds @return list<AuthorizationGrant> */
    private function matchingGrants(array $rows, array $resourceIds, string $ability, Admin $admin, ?AuthorizationContext $context): array
    {
        $grants = [];
        foreach ($rows as $row) {
            if (!in_array((int) $row->resource_id, $resourceIds, true)) {
                continue;
            }
            $abilities = $this->decodeList((string) $row->abilities_json);
            $effect = (string) $row->effect === 'allow' ? 'allow' : 'deny';
            if (!$this->grantMatchesAbility($abilities, $ability)) {
                continue;
            }

            $conditions = $this->decodeNullable((string) ($row->conditions_json ?? ''));
            if (!$this->conditionsMatch($conditions, $admin, $ability, $context)) {
                continue;
            }

            $scopeType = $this->nullableString($row->scope_type ?? null);
            $scopeValue = $this->decodeNullable((string) ($row->scope_value_json ?? ''));
            $grants[] = new AuthorizationGrant(
                (int) $row->id,
                (string) $row->subject_type,
                (string) $row->subject_id,
                $effect,
                $abilities,
                (int) $row->priority,
                $scopeType,
                $scopeValue,
                $conditions,
            );
        }

        return $grants;
    }

    /** @param list<string> $abilities */
    private function grantMatchesAbility(array $abilities, string $ability): bool
    {
        if (in_array($ability, $abilities, true) || in_array('*', $abilities, true)) {
            return true;
        }
        if ($ability === self::ACCESS_ABILITY && in_array(self::LEGACY_MENU_ACCESS_ABILITY, $abilities, true)) {
            return true;
        }

        return false;
    }

    /** @param list<AuthorizationGrant> $grants */
    private function resolveGrants(array $grants): AuthorizationDecision
    {
        $denied = array_values(array_filter($grants, static fn (AuthorizationGrant $grant): bool => $grant->effect === 'deny'));
        if ($denied !== []) {
            return new AuthorizationDecision(false, 'explicit_deny', $denied);
        }
        return new AuthorizationDecision(true, 'merged_grant_allow', $grants);
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $conditions
     */
    private function conditionsMatch(
        ?array $conditions,
        Admin $admin,
        string $ability,
        ?AuthorizationContext $context,
    ): bool {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        $values = [
            'admin_id' => (int) $admin->getKey(),
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
        if (!in_array($field, ['admin_id', 'ability'], true)
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

    /** @return list<object> */
    private function grantRows(Admin $admin): array
    {
        $identity = $this->identity($admin);
        $roleIds = $identity->roleIds;
        $authorizationSubjectId = $identity->authorizationSubjectId ?? (int) $admin->getKey();
        return $this->database->table('admin_grants')
            ->where(function ($query) use ($authorizationSubjectId, $roleIds, $identity): void {
                $query->whereRaw('1 = 0');
                if ($identity->directGrants) {
                    $query->orWhere(function ($direct) use ($authorizationSubjectId): void {
                        $direct->where('subject_type', 'admin')->where('subject_id', (string) $authorizationSubjectId);
                    });
                }
                if ($roleIds !== []) {
                    $query->orWhere(function ($roles) use ($roleIds): void {
                        $roles->where('subject_type', 'role')->whereIn('subject_id', array_map('strval', $roleIds));
                    });
                }
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()->all();
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

        return $path === '' ? '' : '/'.ltrim($path, '/');
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

    /** @return list<string> */
    private function resourceAbilities(object $resource): array
    {
        $meta = $this->decode((string) ($resource->meta_json ?? ''));

        return array_values(array_unique(array_filter(
            array_map('strval', (array) ($meta['abilities'] ?? [])),
            static fn (string $ability): bool => $ability !== '',
        )));
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

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }
}
