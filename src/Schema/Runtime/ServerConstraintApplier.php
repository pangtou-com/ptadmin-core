<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Support\DataObject;

/**
 * 将可信服务端数据范围统一应用到普通查询、树查询和写入后的候选数据。
 */
final class ServerConstraintApplier
{
    public function apply(mixed $builder, mixed $constraint, ?object $schema = null): void
    {
        $constraint = $this->normalize($constraint);
        if ($constraint === null) {
            return;
        }

        $apply = function ($nested, array $node, int $depth = 0) use (&$apply, $schema): void {
            $this->assertNode($node, $depth, $schema);
            $all = !empty($node['all']) && is_array($node['all']) ? array_values($node['all']) : [];
            $any = !empty($node['any']) && is_array($node['any']) ? array_values($node['any']) : [];
            if ($all !== []) {
                $nested->where(function ($group) use ($all, $apply, $depth): void {
                    foreach ($all as $child) {
                        $apply($group, (array) $child, $depth + 1);
                    }
                });

                return;
            }
            if ($any !== []) {
                $nested->where(function ($group) use ($any, $apply, $depth): void {
                    foreach ($any as $index => $child) {
                        if ($index === 0) {
                            $apply($group, (array) $child, $depth + 1);
                            continue;
                        }
                        $group->orWhere(function ($branch) use ($child, $apply, $depth): void {
                            $apply($branch, (array) $child, $depth + 1);
                        });
                    }
                });

                return;
            }

            $field = (string) $node['field'];
            if ((string) $node['operator'] === 'in') {
                $nested->whereIn($field, (array) ($node['value'] ?? []));

                return;
            }
            $nested->where($field, '=', $node['value'] ?? null);
        };
        $apply($builder, $constraint);
    }

    /** @param array<string, mixed> $values */
    public function matches(array $values, mixed $constraint, ?object $schema = null): bool
    {
        $constraint = $this->normalize($constraint);
        if ($constraint === null) {
            return true;
        }

        $matches = function (array $node, int $depth = 0) use (&$matches, $values, $schema): bool {
            $this->assertNode($node, $depth, $schema);
            $all = !empty($node['all']) && is_array($node['all']) ? array_values($node['all']) : [];
            $any = !empty($node['any']) && is_array($node['any']) ? array_values($node['any']) : [];
            if ($all !== []) {
                foreach ($all as $child) {
                    if (!$matches((array) $child, $depth + 1)) {
                        return false;
                    }
                }

                return true;
            }
            if ($any !== []) {
                foreach ($any as $child) {
                    if ($matches((array) $child, $depth + 1)) {
                        return true;
                    }
                }

                return false;
            }

            $actual = $values[(string) $node['field']] ?? null;
            $expected = $node['value'] ?? null;

            return (string) $node['operator'] === 'in'
                ? in_array($actual, (array) $expected, false)
                : $actual == $expected;
        };

        return $matches($constraint);
    }

    /** @return array<string, mixed>|null */
    private function normalize(mixed $constraint): ?array
    {
        if ($constraint instanceof DataObject) {
            $constraint = $constraint->toArray();
        }

        return is_array($constraint) && $constraint !== [] ? $constraint : null;
    }

    /** @param array<string, mixed> $node */
    private function assertNode(array $node, int $depth, ?object $schema): void
    {
        if ($depth > 16) {
            throw $this->error('invalid_input', 'server constraint nesting exceeds limit');
        }
        $all = !empty($node['all']) && is_array($node['all']) ? array_values($node['all']) : [];
        $any = !empty($node['any']) && is_array($node['any']) ? array_values($node['any']) : [];
        $field = trim((string) ($node['field'] ?? ''));
        $operator = strtolower(trim((string) ($node['operator'] ?? '')));
        $hasGroups = $all !== [] || $any !== [];
        $hasLeaf = $field !== '' || $operator !== '' || ($node['value'] ?? null) !== null;
        if ($hasGroups === $hasLeaf || ($all !== [] && $any !== [])) {
            throw $this->error('invalid_input', 'server constraint must contain exactly one leaf or condition group');
        }
        if ($hasGroups) {
            return;
        }
        if ($schema && !$this->isPersistedField($schema, $field) && $field !== 'id') {
            throw $this->error('invalid_input', 'server constraint field is not persisted', ['field' => $field]);
        }
        if (!in_array($operator, ['eq', 'in'], true)) {
            throw $this->error('invalid_input', 'server constraint operator is not supported', [
                'field' => $field,
                'operator' => $operator,
            ]);
        }
        if ($operator === 'in' && !is_array($node['value'] ?? null)) {
            throw $this->error('invalid_input', 'server constraint in operator requires an array value', ['field' => $field]);
        }
    }

    private function isPersistedField(object $schema, string $name): bool
    {
        foreach ((array) $schema->fields as $field) {
            if (($field['name'] ?? '') === $name && !empty($field['persisted'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): SchemaError
    {
        return new SchemaError($code, $message, $details);
    }
}
