<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Auth\AuthorizationGrant;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contract\ServerConstraint;
use PTAdmin\Schema\Contracts\SchemaScopeResolverInterface;

final class SchemaScopeResolver implements SchemaScopeResolverInterface
{
    public function __construct(
        private AuthorizationService $authorization,
        private ConnectionInterface $database,
    ) {
    }

    public function resolve(mixed $subject, string $resourceKey, string $action): ?ServerConstraint
    {
        if (!$subject instanceof Admin || (bool) $subject->is_founder) {
            return null;
        }

        $decision = $this->authorization->decision($subject, $action, $resourceKey);
        if (!$decision->allowed || $decision->grants === []) {
            return $this->none();
        }

        $fields = $this->persistedFields($resourceKey);
        $relations = $this->database->table('admin_user_org_relations')
            ->where('user_id', (int) $subject->getKey())
            ->get()
            ->all();
        $constraints = [];
        foreach ($decision->grants as $grant) {
            $constraint = $this->grantConstraint($grant, $resourceKey, $fields, $relations, (int) $subject->getKey());
            if ($constraint === null) {
                return null;
            }
            $constraints[] = $constraint;
        }

        if ($constraints === []) {
            return $this->none();
        }

        return count($constraints) === 1
            ? $constraints[0]
            : new ServerConstraint(['any' => $constraints]);
    }

    /**
     * @param array<string, true> $fields
     * @param list<object> $relations
     */
    private function grantConstraint(
        AuthorizationGrant $grant,
        string $resourceKey,
        array $fields,
        array $relations,
        int $adminId,
    ): ?ServerConstraint {
        $scopeType = $grant->scopeType ?? 'all';
        if (!in_array($scopeType, ['all', 'self', 'tenant', 'organization', 'department', 'assigned'], true)) {
            return $this->none();
        }

        $all = [];
        if ($grant->tenantId !== null) {
            $tenantField = $this->scopeTargetField('tenant', $resourceKey, $fields);
            if ($tenantField === null) {
                return $this->none();
            }
            $all[] = $this->leaf($tenantField, 'eq', $grant->tenantId);
            $relations = array_values(array_filter(
                $relations,
                static fn (object $relation): bool => (int) ($relation->tenant_id ?? 0) === $grant->tenantId,
            ));
        }

        if ($scopeType === 'all') {
            return $this->combineAll($all);
        }

        if ($scopeType === 'self') {
            $field = $this->scopeField($grant, ['created_by', 'admin_id', 'user_id', 'owner_id'], $fields);
            if ($field === null) {
                return $this->none();
            }
            $all[] = $this->leaf($field, 'eq', $adminId);

            return $this->combineAll($all);
        }

        if ($scopeType === 'tenant') {
            $field = $this->scopeTargetField('tenant', $resourceKey, $fields);
            if ($field === null) {
                return $this->none();
            }
            $ids = $this->scopeIds($grant->scopeValue, ['tenant_id', 'tenant_ids', 'id', 'ids']);
            if ($grant->tenantId !== null) {
                $ids = $ids === [] ? [$grant->tenantId] : array_values(array_intersect($ids, [$grant->tenantId]));
            } elseif ($ids === []) {
                $ids = $this->relationIds($relations, 'tenant_id');
            }
            $all[] = $this->idsConstraint($field, $ids);

            return $this->combineAll($all);
        }

        if ($scopeType === 'organization') {
            $field = $this->scopeTargetField('organization', $resourceKey, $fields);
            if ($field === null) {
                return $this->none();
            }
            $assigned = $this->relationIds($relations, 'organization_id');
            $configured = $this->scopeIds($grant->scopeValue, ['organization_id', 'organization_ids', 'id', 'ids']);
            $ids = $configured === [] ? $assigned : array_values(array_intersect($configured, $assigned));
            $all[] = $this->idsConstraint($field, $ids);

            return $this->combineAll($all);
        }

        if ($scopeType === 'department') {
            $field = $this->scopeTargetField('department', $resourceKey, $fields);
            if ($field === null) {
                return $this->none();
            }
            $assigned = $this->relationIds($relations, 'department_id');
            $configured = $this->scopeIds($grant->scopeValue, ['department_id', 'department_ids', 'id', 'ids']);
            $ids = $configured === [] ? $assigned : array_values(array_intersect($configured, $assigned));
            $all[] = $this->idsConstraint($field, $ids);

            return $this->combineAll($all);
        }

        $assigned = $this->assignedConstraint($resourceKey, $fields, $relations);
        if ($assigned === null) {
            return $this->none();
        }
        $all[] = $assigned;

        return $this->combineAll($all);
    }

    /** @param array<string, true> $fields @param list<object> $relations */
    private function assignedConstraint(string $resourceKey, array $fields, array $relations): ?ServerConstraint
    {
        $mapping = [];
        foreach (['tenant', 'organization', 'department'] as $scopeType) {
            $target = $this->scopeTargetField($scopeType, $resourceKey, $fields);
            if ($target !== null) {
                $mapping[$target] = $scopeType.'_id';
            }
        }
        if ($mapping === []) {
            return null;
        }

        $branches = [];
        foreach ($relations as $relation) {
            $all = [];
            foreach ($mapping as $target => $relationField) {
                $value = $relation->{$relationField} ?? null;
                if ($value !== null) {
                    $all[] = $this->leaf($target, 'eq', (int) $value);
                }
            }
            if ($all !== []) {
                $branches[] = $this->combineAll($all);
            }
        }
        if ($branches === []) {
            return $this->none();
        }

        return count($branches) === 1
            ? $branches[0]
            : new ServerConstraint(['any' => $branches]);
    }

    /** @param array<string, true> $fields */
    private function scopeTargetField(string $scopeType, string $resourceKey, array $fields): ?string
    {
        $field = $scopeType.'_id';
        if (isset($fields[$field])) {
            return $field;
        }
        $selfResources = [
            'tenant' => 'admin.management_tenants',
            'organization' => 'admin.management_organizations',
            'department' => 'admin.management_departments',
        ];

        return ($selfResources[$scopeType] ?? null) === $resourceKey ? 'id' : null;
    }

    /** @param array<string, true> $fields @param list<string> $fallbacks */
    private function scopeField(AuthorizationGrant $grant, array $fallbacks, array $fields): ?string
    {
        $configured = is_array($grant->scopeValue) ? ($grant->scopeValue['field'] ?? null) : null;
        if (is_string($configured) && isset($fields[$configured])) {
            return $configured;
        }
        foreach ($fallbacks as $field) {
            if (isset($fields[$field])) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, true> */
    private function persistedFields(string $resourceKey): array
    {
        $resource = $this->database->table('schema_resources')
            ->where('resource_key', $resourceKey)
            ->first(['id', 'active_version_id']);
        if (!$resource || $resource->active_version_id === null) {
            return ['id' => true];
        }

        $fields = ['id' => true];
        foreach ($this->database->table('schema_field_projections')
            ->where('resource_id', (int) $resource->id)
            ->where('version_id', (int) $resource->active_version_id)
            ->where('persisted', 1)
            ->pluck('name') as $name
        ) {
            $fields[(string) $name] = true;
        }

        return $fields;
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

    /** @param list<object> $relations @return list<int> */
    private function relationIds(array $relations, string $field): array
    {
        $ids = [];
        foreach ($relations as $relation) {
            $value = $relation->{$field} ?? null;
            if ($value !== null && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $ids */
    private function idsConstraint(string $field, array $ids): ServerConstraint
    {
        return $this->leaf($field, 'in', array_values(array_unique(array_map('intval', $ids))));
    }

    private function leaf(string $field, string $operator, mixed $value): ServerConstraint
    {
        return new ServerConstraint(['field' => $field, 'operator' => $operator, 'value' => $value]);
    }

    /** @param list<ServerConstraint> $constraints */
    private function combineAll(array $constraints): ?ServerConstraint
    {
        if ($constraints === []) {
            return null;
        }

        return count($constraints) === 1
            ? $constraints[0]
            : new ServerConstraint(['all' => $constraints]);
    }

    private function none(): ServerConstraint
    {
        return $this->leaf('id', 'in', []);
    }
}
