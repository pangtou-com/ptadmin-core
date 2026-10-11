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
        private DataScopeRegistry $scopes,
        private ?DataScopeBoundaryInterface $boundary = null,
    ) {
    }

    public function resolve(mixed $subject, string $resourceKey, string $action): ?ServerConstraint
    {
        if (!$subject instanceof Admin) {
            return $this->none();
        }
        if ($this->authorization->identity($subject)->superuser) {
            return $this->boundary?->resolve($subject, $resourceKey, $action, $this->activeSchema($resourceKey) ?? []);
        }

        $decision = $this->authorization->decision($subject, $action, $resourceKey);
        if (!$decision->allowed || $decision->grants === []) {
            return $this->none();
        }

        $schema = $this->activeSchema($resourceKey);
        if ($schema === null) {
            return $this->none();
        }

        $constraints = [];
        foreach ($decision->grants as $grant) {
            $constraint = $this->grantConstraint($grant, $subject, $resourceKey, $action, $schema);
            if ($constraint === null) {
                return $this->boundary?->resolve($subject, $resourceKey, $action, $schema);
            }
            $constraints[] = $constraint;
        }

        if ($constraints === []) {
            return $this->none();
        }

        $granted = count($constraints) === 1
            ? $constraints[0]
            : new ServerConstraint(['any' => $constraints]);
        $boundary = $this->boundary?->resolve($subject, $resourceKey, $action, $schema);

        return $boundary === null ? $granted : new ServerConstraint(['all' => [$granted, $boundary]]);
    }

    /** @param array<string, mixed> $schema */
    private function grantConstraint(
        AuthorizationGrant $grant,
        Admin $subject,
        string $resourceKey,
        string $action,
        array $schema,
    ): ?ServerConstraint {
        if ($grant->scopeType === 'all') {
            return null;
        }
        if ($grant->scopeType === 'self') {
            $ownerField = $this->ownerField($schema);
            if ($ownerField === null) {
                return $this->none();
            }

            return new ServerConstraint([
                'field' => $ownerField,
                'operator' => 'eq',
                'value' => (int) $subject->getKey(),
            ]);
        }
        if ($grant->scopeType === null
            || !$this->scopes->validate($grant->scopeType, $grant->scopeValue)
        ) {
            return $this->none();
        }

        return $this->scopes->resolve(
            $grant->scopeType,
            $subject,
            $resourceKey,
            $action,
            $grant->scopeValue,
            $schema,
        ) ?? $this->none();
    }

    /** @return array<string, mixed>|null */
    private function activeSchema(string $resourceKey): ?array
    {
        $resource = $this->database->table('schema_resources')
            ->where('resource_key', $resourceKey)
            ->whereNull('deleted_at')
            ->first(['active_version_id']);
        if (!$resource || (int) $resource->active_version_id <= 0) {
            return null;
        }

        $encoded = $this->database->table('schema_resource_versions')
            ->where('id', (int) $resource->active_version_id)
            ->value('schema_json');
        if (is_array($encoded)) {
            return $encoded;
        }
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }
        $schema = json_decode($encoded, true);

        return is_array($schema) ? $schema : null;
    }

    /** @param array<string, mixed> $schema */
    private function ownerField(array $schema): ?string
    {
        $extensions = is_array($schema['extensions'] ?? null) ? $schema['extensions'] : [];
        $authorization = is_array($extensions['authorization'] ?? null) ? $extensions['authorization'] : [];
        $ownerField = (string) ($authorization['owner_field'] ?? '');
        if ($ownerField === '') {
            return null;
        }

        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if ((string) ($field['name'] ?? '') === $ownerField && !empty($field['persisted'])) {
                return $ownerField;
            }
        }

        return null;
    }

    private function none(): ServerConstraint
    {
        return new ServerConstraint(['field' => 'id', 'operator' => 'in', 'value' => []]);
    }
}
