<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Core\Config\Models\ConfigValue;
use PTAdmin\Schema\Definition\Schema;
use Throwable;

/**
 * 配置定义和值的统一服务。值按 global 作用域保存为一条 JSON 对象记录。
 */
final class ConfigService
{
    public function __construct(private ConfigValueCodec $codec)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function syncDefinition(array $input): array
    {
        $ownerType = trim((string) ($input['owner_type'] ?? ''));
        $ownerCode = trim((string) ($input['owner_code'] ?? ''));
        $configKey = trim((string) ($input['config_key'] ?? ''));
        $groupCode = trim((string) ($input['group_code'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        if (!in_array($ownerType, ['core', 'addon', 'app'], true)) {
            throw new ConfigException('Configuration owner type is invalid.', 'config_owner_invalid');
        }
        if ($ownerCode === '' || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $ownerCode) !== 1) {
            throw new ConfigException('Configuration owner code is invalid.', 'config_owner_invalid');
        }
        if ($configKey === '' || preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $configKey) !== 1) {
            throw new ConfigException('Configuration key is invalid.', 'config_key_invalid');
        }
        try {
            ConfigGroups::assert($groupCode);
        } catch (Throwable $exception) {
            throw new ConfigException($exception->getMessage(), 'config_group_invalid');
        }
        if ($title === '') {
            throw new ConfigException('Configuration title is required.', 'config_definition_invalid');
        }

        $schemaData = (array) ($input['schema'] ?? []);
        $schemaData['name'] = (string) ($schemaData['name'] ?? str_replace(['.', '-'], '_', $configKey));
        $schemaData['title'] = (string) ($schemaData['title'] ?? $title);
        $schema = new Schema($schemaData);
        $issues = $schema->validate();
        if ($issues !== []) {
            throw new ConfigException($issues[0]['message'], 'config_schema_invalid', ['issues' => $issues]);
        }
        $normalized = $schema->toArray();
        $checksum = $schema->checksum();
        $now = now();

        $definition = ConfigDefinition::query()->firstOrNew([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
        ]);
        $definition->fill([
            'group_code' => $groupCode,
            'title' => $title,
            'description' => (string) ($input['description'] ?? ''),
            'schema_json' => $normalized,
            'checksum' => $checksum,
            'status' => array_key_exists('status', $input) ? (bool) $input['status'] : true,
            'updated_at' => $now,
        ]);
        if (!$definition->exists) {
            $definition->created_at = $now;
        }
        $definition->save();

        return $this->definitionPayload($definition);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(array $filters = []): array
    {
        $query = ConfigDefinition::query()->where('status', true)->orderBy('group_code')->orderBy('id');
        foreach (['owner_type', 'owner_code', 'group_code', 'config_key'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        return $query->get()->map(fn (ConfigDefinition $definition): array => $this->definitionPayload($definition))->all();
    }

    /** @return array<string, mixed> */
    public function get(string $ownerType, string $ownerCode, string $configKey, string $scopeType = 'global', string $scopeId = ''): array
    {
        $this->assertScope($scopeType, $scopeId);
        $definition = $this->definition($ownerType, $ownerCode, $configKey);
        $value = ConfigValue::query()->where([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ])->first();
        $stored = $value ? (array) $value->value_json : [];

        return [
            'definition' => $this->definitionPayload($definition),
            'scope' => ['type' => $scopeType, 'id' => $scopeId],
            'data' => $this->codec->decode((array) $definition->schema_json, $stored),
            'checksum' => $value?->checksum,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function put(string $ownerType, string $ownerCode, string $configKey, array $data, string $scopeType = 'global', string $scopeId = ''): array
    {
        $definition = $this->definition($ownerType, $ownerCode, $configKey);
        $this->assertScope($scopeType, $scopeId);
        $existing = ConfigValue::query()->where([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ])->first();
        try {
            $encoded = $this->codec->encode((array) $definition->schema_json, $data, $existing ? (array) $existing->value_json : []);
        } catch (Throwable $exception) {
            throw $exception instanceof ConfigException
                ? $exception
                : new ConfigException($exception->getMessage(), 'config_value_invalid');
        }
        $checksum = hash('sha256', json_encode($encoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $now = now();
        $value = $existing ?: new ConfigValue();
        $value->fill([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'value_json' => $encoded,
            'checksum' => $checksum,
            'updated_at' => $now,
        ]);
        if (!$value->exists) {
            $value->created_at = $now;
        }
        $value->save();

        return $this->get($ownerType, $ownerCode, $configKey, $scopeType, $scopeId);
    }

    private function definition(string $ownerType, string $ownerCode, string $configKey): ConfigDefinition
    {
        $definition = ConfigDefinition::query()->where([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
            'status' => true,
        ])->first();
        if (!$definition) {
            throw new ConfigException('Configuration definition was not found.', 'config_not_found', [
                'owner_type' => $ownerType,
                'owner_code' => $ownerCode,
                'config_key' => $configKey,
            ]);
        }

        return $definition;
    }

    private function assertScope(string $scopeType, string $scopeId): void
    {
        if ($scopeType !== 'global' || $scopeId !== '') {
            throw new ConfigException('Only the global configuration scope is available.', 'config_scope_not_available');
        }
    }

    /** @return array<string, mixed> */
    private function definitionPayload(ConfigDefinition $definition): array
    {
        return [
            'id' => (int) $definition->id,
            'group_code' => (string) $definition->group_code,
            'group_title' => ConfigGroups::all()[(string) $definition->group_code] ?? '',
            'config_key' => (string) $definition->config_key,
            'owner_type' => (string) $definition->owner_type,
            'owner_code' => (string) $definition->owner_code,
            'title' => (string) $definition->title,
            'description' => (string) $definition->description,
            'schema' => (array) $definition->schema_json,
            'checksum' => (string) $definition->checksum,
            'status' => (bool) $definition->status,
        ];
    }
}
