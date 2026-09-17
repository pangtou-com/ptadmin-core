<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

/**
 * 校验 Schema 的标识、字段类型、引用、规则、选项和数据库约束，返回可定位的协议问题列表。
 */
final class Validator
{
    /** @param array<string, mixed> $schema */
    public function validate(array $schema): array
    {
        $issues = [];
        if (($schema['name'] ?? '') === '' || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', (string) ($schema['name'] ?? ''))) {
            $issues[] = $this->issue('invalid_schema', 'name', 'schema name is required');
        }
        if (($schema['title'] ?? '') === '') {
            $issues[] = $this->issue('invalid_schema', 'title', 'schema title is required');
        }
        $names = [];
        foreach ((array) ($schema['fields'] ?? []) as $index => $field) {
            $path = 'fields.'.$index;
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
                $issues[] = $this->issue('invalid_field', $path.'.name', 'field name is invalid');
            } elseif (isset($names[$name])) {
                $issues[] = $this->issue('duplicate_field', $path.'.name', 'field name is duplicated');
            }
            $names[$name] = $field;
            $type = strtolower((string) ($field['type'] ?? ''));
            if (!FieldType::supported($type)) {
                $issues[] = $this->issue('invalid_field_type', $path.'.type', 'field type is not supported');
            }
            if (!empty($field['multiple']) && FieldType::supported($type) && empty(FieldType::specs()[$type]['multiple'])) {
                $issues[] = $this->issue('invalid_field_multiple', $path.'.multiple', 'field type does not support multiple values');
            }
            if (!empty($field['searchable']) && FieldType::supported($type) && empty(FieldType::specs()[$type]['filterable'])) {
                $issues[] = $this->issue('invalid_field', $path.'.searchable', 'field type does not support search');
            }
            if (!empty($field['sortable']) && FieldType::supported($type) && empty(FieldType::specs()[$type]['sortable'])) {
                $issues[] = $this->issue('invalid_field', $path.'.sortable', 'field type does not support sort');
            }
            if (array_key_exists('default', $field) && isset($field['default_expr'])) {
                $issues[] = $this->issue('invalid_field', $path.'.default_expr', 'default and default_expr cannot be used together');
            }
            if (isset($field['default_expr'])) {
                $this->validateExpression((array) $field['default_expr'], $path.'.default_expr', $issues);
            }
            if (isset($field['reference'])) {
                $reference = (array) $field['reference'];
                foreach (['resource', 'value_field', 'label_field'] as $key) {
                    if (trim((string) ($reference[$key] ?? '')) === '') {
                        $issues[] = $this->issue('invalid_reference', $path.'.reference.'.$key, 'field reference requires '.$key);
                    }
                }
                if (!in_array($type, ['string', 'number', 'integer'], true)) {
                    $issues[] = $this->issue('invalid_reference', $path.'.reference', 'field reference requires string, number, or integer field type');
                }
            }
            foreach ((array) ($field['validation'] ?? []) as $ruleIndex => $rule) {
                $ruleType = strtolower((string) ($rule['type'] ?? ''));
                if (!in_array($ruleType, ['required', 'max_length', 'min_length', 'min', 'max', 'pattern', 'format', 'complexity', 'range'], true)) {
                    $issues[] = $this->issue('invalid_validation_rule', $path.'.validation.'.$ruleIndex.'.type', 'validation rule type is not supported');
                }
                $this->validateRule((array) $rule, $type, !empty($field['multiple']), $path.'.validation.'.$ruleIndex, $issues);
            }
            if (isset($field['options'])) {
                $mode = strtolower((string) (($field['options']['mode'] ?? '')));
                if ($mode !== '' && !in_array($mode, ['static', 'dictionary', 'reference', 'remote'], true)) {
                    $issues[] = $this->issue('invalid_options', $path.'.options.mode', 'options mode is not supported');
                }
                if ($mode === 'reference' && empty($field['reference'])) {
                    $issues[] = $this->issue('invalid_options', $path.'.options.reference', 'reference options require field reference config');
                }
                if ($mode === 'static' && !isset($field['options']['items'])) {
                    $issues[] = $this->issue('invalid_options', $path.'.options.items', 'static options require items');
                }
                $policy = strtolower((string) ($field['options']['value_policy'] ?? ''));
                if ($policy !== '' && !in_array($policy, ['open', 'closed'], true)) {
                    $issues[] = $this->issue('invalid_options', $path.'.options.value_policy', 'options value_policy only supports closed or open');
                }
                if ($mode === 'dictionary' && trim((string) ($field['options']['dictionary']['code'] ?? '')) === '') {
                    $issues[] = $this->issue('invalid_options', $path.'.options.dictionary.code', 'dictionary options require code');
                }
                if ($mode === 'remote') {
                    $target = strtolower((string) ($field['options']['remote']['target'] ?? 'provider'));
                    if (!in_array($target, ['provider', 'client'], true)) {
                        $issues[] = $this->issue('invalid_options', $path.'.options.remote.target', 'remote options target must be provider or client');
                    } elseif ($target === 'provider' && trim((string) ($field['options']['remote']['provider'] ?? '')) === '') {
                        $issues[] = $this->issue('invalid_options', $path.'.options.remote.provider', 'provider remote options require provider');
                    } elseif ($target === 'client' && trim((string) ($field['options']['remote']['url'] ?? '')) === '') {
                        $issues[] = $this->issue('invalid_options', $path.'.options.remote.url', 'client remote options require url');
                    }
                }
            }
            $extensions = is_array($field['extensions'] ?? null) ? $field['extensions'] : (array) ($field['extensions'] ?? []);
            if (isset($extensions['generator'])) {
                $generator = (array) $extensions['generator'];
                $generatorType = strtolower((string) ($generator['type'] ?? ''));
                if ($type !== 'string' || empty($field['persisted'])) {
                    $issues[] = $this->issue('invalid_extension', $path.'.extensions.generator', 'generator extension requires persisted string field');
                }
                if (!in_array($generatorType, ['sequence', 'slug'], true)) {
                    $issues[] = $this->issue('invalid_extension', $path.'.extensions.generator.type', 'generator type is not supported');
                }
                if ($generatorType === 'sequence' && !preg_match('/\{seq(?::[0-9]+)?\}/', (string) ($generator['pattern'] ?? ''))) {
                    $issues[] = $this->issue('invalid_extension', $path.'.extensions.generator.pattern', 'sequence generator pattern requires seq token');
                }
                if ($generatorType === 'slug' && (!isset($generator['source_field']) || $generator['source_field'] === $name)) {
                    $issues[] = $this->issue('invalid_extension', $path.'.extensions.generator.source_field', 'slug generator source_field is invalid');
                }
            }
            if (isset($extensions['secret']) && !in_array($type, ['string', 'text'], true)) {
                $issues[] = $this->issue('invalid_extension', $path.'.extensions.secret', 'secret extension requires string-like field type');
            }
            if (isset($field['normalize'])) {
                $case = strtolower((string) ($field['normalize']['case'] ?? ''));
                if (!in_array($case, ['', 'lower', 'upper'], true)) {
                    $issues[] = $this->issue('invalid_field', $path.'.normalize.case', 'normalize case must be lower or upper');
                }
                if ((!empty($field['normalize']['accepted_formats']) || !empty($field['normalize']['timezone'])) && !in_array($type, ['date', 'datetime'], true)) {
                    $issues[] = $this->issue('invalid_field', $path.'.normalize', 'date formats and timezone only support date or datetime fields');
                }
            }
            if (isset($field['display'])) {
                $formatter = strtolower((string) ($field['display']['formatter'] ?? ''));
                if (!in_array($formatter, ['', 'datetime', 'date', 'number', 'boolean', 'enum'], true)) {
                    $issues[] = $this->issue('invalid_field', $path.'.display.formatter', 'display formatter is not supported');
                }
                if (isset($field['display']['precision']) && (int) $field['display']['precision'] < 0) {
                    $issues[] = $this->issue('invalid_field', $path.'.display.precision', 'display precision cannot be negative');
                }
            }
        }
        $constraints = is_array($schema['constraints'] ?? null) ? $schema['constraints'] : (array) ($schema['constraints'] ?? []);
        // Runtime/publish always provide these physical columns even when a Schema omits them.
        $constraintFields = $names + [
            'id' => true,
            'created_at' => true,
            'updated_at' => true,
        ];
        if (!empty(((array) ($schema['capabilities'] ?? []))['allow_recycle'])) {
            $constraintFields['deleted_at'] = true;
        }
        foreach (['indexes', 'uniques'] as $constraintType) {
            foreach ((array) (($constraints[$constraintType] ?? [])) as $index => $constraint) {
                $fields = (array) ($constraint['fields'] ?? []);
                if ($fields === []) {
                    $issues[] = $this->issue('invalid_constraint', 'constraints.'.$constraintType.'.'.$index.'.fields', 'constraint fields are required');
                }
                foreach ($fields as $fieldName) {
                    if (!isset($constraintFields[$fieldName])) {
                        $issues[] = $this->issue('invalid_constraint', 'constraints.'.$constraintType.'.'.$index.'.fields', 'constraint field does not exist');
                    }
                }
            }
        }
        $this->validateState($schema, $names, $issues);
        $this->validateTree($schema, $names, $issues);
        return $issues;
    }

    private function validateState(array $schema, array $fields, array &$issues): void
    {
        $capabilities = (array) ($schema['capabilities'] ?? []);
        $allowDraft = !empty($capabilities['allow_draft']);
        if (!isset($schema['state'])) {
            if ($allowDraft) {
                $issues[] = $this->issue('invalid_state', 'state', 'state config is required when allow_draft is enabled');
            }
            return;
        }
        $state = (array) $schema['state'];
        $fieldName = (string) ($state['field'] ?? '');
        $field = $fields[$fieldName] ?? null;
        if (!$field) {
            $issues[] = $this->issue('invalid_state', 'state.field', 'state field must exist');
        } else {
            $extensions = (array) ($field['extensions'] ?? []);
            if (empty($field['persisted']) || !empty($field['multiple']) || !empty($field['readonly']) || !empty($extensions['generator']) || !empty($extensions['mirror']) || !empty($extensions['clone'])) {
                $issues[] = $this->issue('invalid_state', 'state.field', 'state field must be persisted, single and writable');
            }
        }
        $values = (array) ($state['values'] ?? []);
        if ($values === []) {
            $issues[] = $this->issue('invalid_state', 'state.values', 'state values are required');
        }
        $hasDraft = false;
        foreach ($values as $value => $definition) {
            $definition = (array) $definition;
            $kind = (string) (($definition['kind'] ?? ''));
            if ((string) $value === '' || !in_array($kind, ['draft', 'workflow_pending', 'workflow_rejected', 'active', 'inactive', 'archived'], true)) {
                $issues[] = $this->issue('invalid_state', 'state.values.'.(string) $value, 'state value or kind is invalid');
            }
            $hasDraft = $hasDraft || $kind === 'draft';
        }
        if ($allowDraft && !$hasDraft) {
            $issues[] = $this->issue('invalid_state', 'state.values', 'state values must include kind=draft when allow_draft is enabled');
        }
    }

    private function validateTree(array $schema, array $fields, array &$issues): void
    {
        $tree = (array) ($schema['tree'] ?? []);
        if (empty($tree['enabled'])) {
            return;
        }
        foreach (['parent_field' => 'parent_id', 'order_field' => 'sort'] as $key => $default) {
            $name = (string) (($tree[$key] ?? '') ?: $default);
            $field = $fields[$name] ?? null;
            if (!$field || empty($field['persisted']) || !empty($field['multiple']) || !in_array($field['type'] ?? '', ['number', 'integer'], true)) {
                $issues[] = $this->issue('invalid_tree', 'tree.'.$key, 'tree field must exist and use a persisted single number or integer field');
            }
        }
    }

    private function validateExpression(array $expression, string $path, array &$issues): void
    {
        $type = strtolower((string) ($expression['type'] ?? ''));
        if (!in_array($type, ['literal', 'now', 'today', 'start_of_day', 'end_of_day', 'start_of_month', 'end_of_month'], true)) {
            $issues[] = $this->issue('invalid_expression', $path.'.type', 'value expression type is not supported');
        } elseif ($type === 'literal' && !array_key_exists('value', $expression)) {
            $issues[] = $this->issue('invalid_expression', $path.'.value', 'literal expression requires value');
        }
    }

    private function validateRule(array $rule, string $fieldType, bool $multiple, string $path, array &$issues): void
    {
        $type = strtolower((string) ($rule['type'] ?? ''));
        $value = $rule['value'] ?? null;
        if (in_array($type, ['max_length', 'min_length'], true) && (!is_numeric($value) || (int) $value <= 0 || (float) $value !== (float) (int) $value)) {
            $issues[] = $this->issue('invalid_validation_rule', $path.'.value', $type.' requires positive integer value');
        }
        if (in_array($type, ['min', 'max'], true)) {
            if (isset($rule['value_expr']) && array_key_exists('value', $rule)) {
                $issues[] = $this->issue('invalid_validation_rule', $path.'.value_expr', $type.' cannot use both value and value_expr');
            } elseif (isset($rule['value_expr'])) {
                $this->validateExpression((array) $rule['value_expr'], $path.'.value_expr', $issues);
            } elseif (!is_int($value) && !is_float($value)) {
                $issues[] = $this->issue('invalid_validation_rule', $path.'.value', $type.' requires numeric value or value_expr');
            }
        }
        if ($type === 'pattern' && (!is_string($value) || @preg_match('~'.$value.'~u', '') === false)) {
            $issues[] = $this->issue('invalid_validation_rule', $path.'.value', 'pattern must be a valid regular expression');
        }
        if ($type === 'format' && !in_array($value, ['email', 'phone', 'cn_mobile', 'cn_id_card', 'url', 'uuid', 'ip', 'ipv4', 'ipv6', 'lower_alpha', 'alpha', 'alpha_num', 'slug', 'identifier', 'color'], true)) {
            $issues[] = $this->issue('invalid_validation_rule', $path.'.value', 'format is not supported');
        }
        if ($type === 'complexity') {
            $params = (array) ($rule['params'] ?? []);
            foreach (['min_length', 'max_length'] as $key) {
                if (isset($params[$key]) && (!is_int($params[$key]) || $params[$key] <= 0)) {
                    $issues[] = $this->issue('invalid_validation_rule', $path.'.params.'.$key, $key.' requires positive integer value');
                }
            }
            foreach (['require_lower', 'require_upper', 'require_digit', 'require_symbol'] as $key) {
                if (isset($params[$key]) && !is_bool($params[$key])) {
                    $issues[] = $this->issue('invalid_validation_rule', $path.'.params.'.$key, $key.' requires boolean value');
                }
            }
        }
        if ($type === 'range' && ($fieldType !== 'json' || $multiple || array_key_exists('value', $rule) || isset($rule['value_expr']))) {
            $issues[] = $this->issue('invalid_validation_rule', $path, 'range validation requires a single json field and params only');
        }
    }

    /** @return array{code:string,path:string,message:string} */
    private function issue(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }
}
