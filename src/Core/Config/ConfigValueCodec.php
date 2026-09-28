<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use InvalidArgumentException;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Support\FieldProtection;

/**
 * 按配置 Schema 处理对象值。未知字段、类型、必填字段和封闭选项在写入前拒绝。
 */
final class ConfigValueCodec
{
    /**
     * @param array<string, mixed> $schemaData
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public function encode(array $schemaData, array $input, array $existing = []): array
    {
        $schema = new Schema($schemaData);
        $issues = $schema->validate();
        if ($issues !== []) {
            throw new InvalidArgumentException($issues[0]['message']);
        }

        $fields = [];
        foreach ((array) $schema->fields as $field) {
            $fields[(string) $field['name']] = (array) $field;
        }
        foreach ($input as $name => $_value) {
            if (!isset($fields[$name])) {
                throw new InvalidArgumentException('Unknown configuration field: '.$name);
            }
        }

        $result = $existing;
        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $input)) {
                if (FieldProtection::mode($field) === 'encrypted' && ($input[$name] === '' || $input[$name] === null)) {
                    continue;
                }
                $result[$name] = $this->cast($input[$name], $field);
                $this->validateOptions($result[$name], $field);
                $result[$name] = FieldProtection::store($result[$name], $field);
                continue;
            }
            if (array_key_exists($name, $existing)) {
                continue;
            }
            if (array_key_exists('default', $field)) {
                $result[$name] = FieldProtection::store($this->cast($field['default'], $field), $field);
                continue;
            }
            if (!empty($field['required'])) {
                throw new InvalidArgumentException('Required configuration field is missing: '.$name);
            }
        }

        foreach ($fields as $name => $field) {
            if (FieldProtection::mode($field) === 'encrypted' && !empty($field['required']) && !array_key_exists($name, $result)) {
                throw new InvalidArgumentException('Required configuration field is missing: '.$name);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schemaData
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function decode(array $schemaData, array $stored, bool $includeDefaults = true): array
    {
        $schema = new Schema($schemaData);
        $result = [];
        foreach ($schema->toArray()['fields'] as $field) {
            $name = (string) ($field['name'] ?? '');
            if (FieldProtection::hidden($field)) {
                continue;
            }
            if (!array_key_exists($name, $stored)) {
                if ($includeDefaults && array_key_exists('default', $field)) {
                    $result[$name] = FieldProtection::output(FieldProtection::store($field['default'], $field), $field);
                }
                continue;
            }
            $result[$name] = FieldProtection::output($stored[$name], $field);
        }

        return $result;
    }

    /**
     * Normalize a submitted object into the persisted override object.
     * Defaults are intentionally omitted so definition changes can flow into
     * fields that the administrator has not explicitly overridden.
     *
     * @param array<string, mixed> $schemaData
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public function encodeOverrides(array $schemaData, array $input, array $existing = []): array
    {
        $encoded = $this->encode($schemaData, $input, $existing);
        $schema = new Schema($schemaData);
        $fields = [];
        foreach ((array) $schema->fields as $field) {
            $fields[(string) $field['name']] = (array) $field;
        }

        $overrides = $existing;
        foreach ($input as $name => $_value) {
            if (!array_key_exists($name, $encoded)) {
                unset($overrides[$name]);
                continue;
            }
            $field = $fields[$name] ?? [];
            if (array_key_exists('default', $field) && $encoded[$name] === $field['default']) {
                unset($overrides[$name]);
                continue;
            }
            $overrides[$name] = $encoded[$name];
        }

        return $overrides;
    }

    /** @param array<string, mixed> $field */
    private function cast(mixed $value, array $field): mixed
    {
        if (!empty($field['multiple'])) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('Configuration field requires an array: '.$field['name']);
            }
            return array_map(fn (mixed $item): mixed => $this->castScalar($item, (string) ($field['type'] ?? 'string')), $value);
        }

        return $this->castScalar($value, (string) ($field['type'] ?? 'string'));
    }

    private function castScalar(mixed $value, string $type): mixed
    {
        return match (strtolower($type)) {
            'integer' => is_int($value) || is_numeric($value) ? (int) $value : throw new InvalidArgumentException('Configuration integer value is invalid.'),
            'number' => is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : throw new InvalidArgumentException('Configuration number value is invalid.'),
            'boolean' => is_bool($value) ? $value : throw new InvalidArgumentException('Configuration boolean value is invalid.'),
            'json' => is_array($value) ? $value : throw new InvalidArgumentException('Configuration JSON value is invalid.'),
            default => $this->castString($value),
        };
    }

    private function castString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Configuration string value is invalid.');
    }

    /** @param array<string, mixed> $field */
    private function validateOptions(mixed $value, array $field): void
    {
        $options = (array) ($field['options'] ?? []);
        if (strtolower((string) ($options['mode'] ?? '')) !== 'static'
            || strtolower((string) ($options['value_policy'] ?? 'open')) !== 'closed') {
            return;
        }
        $allowed = array_map(static fn (array $item): mixed => $item['value'] ?? null, (array) ($options['items'] ?? []));
        $values = !empty($field['multiple']) && is_array($value) ? $value : [$value];
        foreach ($values as $candidate) {
            if (!in_array($candidate, $allowed, true)) {
                throw new InvalidArgumentException('Configuration option value is not allowed: '.$field['name']);
            }
        }
    }
}
