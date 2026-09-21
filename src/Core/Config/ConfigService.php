<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Core\Config\Models\ConfigValue;
use PTAdmin\Schema\Definition\Schema;
use Illuminate\Support\Facades\Cache;
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
        $exposure = (string) ($input['exposure'] ?? 'internal');
        $activationMode = (string) ($input['activation_mode'] ?? 'immediate');
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
        if (!in_array($exposure, ['internal', 'public'], true)) {
            throw new ConfigException('Configuration exposure is invalid.', 'config_definition_invalid');
        }
        if (!in_array($activationMode, ['immediate', 'page_reload'], true)) {
            throw new ConfigException('Configuration activation mode is invalid.', 'config_definition_invalid');
        }

        $schemaData = (array) ($input['schema'] ?? []);
        $schemaData['name'] = self::schemaName($configKey, $schemaData['name'] ?? null);
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
            'exposure' => $exposure,
            'activation_mode' => $activationMode,
            'schema_json' => $normalized,
            'checksum' => $checksum,
            'status' => array_key_exists('status', $input) ? (bool) $input['status'] : true,
            'updated_at' => $now,
        ]);
        if (!$definition->exists) {
            $definition->created_at = $now;
        }
        $definition->save();
        Cache::forget($this->definitionCacheKey($ownerType, $ownerCode, $configKey));
        Cache::forget($this->valueCacheKey($ownerType, $ownerCode, $configKey, 'global', ''));

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
    public function deactivateDefinition(string $ownerType, string $ownerCode, string $configKey): array
    {
        $definition = ConfigDefinition::query()->where([
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'config_key' => $configKey,
        ])->first();
        if (!$definition) {
            throw new ConfigException('Configuration definition was not found.', 'config_not_found');
        }
        $definition->status = false;
        $definition->save();
        Cache::forget($this->definitionCacheKey($ownerType, $ownerCode, $configKey));

        return $this->definitionPayload($definition);
    }

    /** @return array<string, mixed> */
    public function get(string $ownerType, string $ownerCode, string $configKey, string $scopeType = 'global', string $scopeId = ''): array
    {
        $this->assertScope($scopeType, $scopeId);
        $definition = $this->definition($ownerType, $ownerCode, $configKey);
        $valuePayload = Cache::remember(
            $this->valueCacheKey($ownerType, $ownerCode, $configKey, $scopeType, $scopeId),
            3600,
            static function () use ($ownerType, $ownerCode, $configKey, $scopeType, $scopeId): ?array {
                $value = ConfigValue::query()->where([
                    'owner_type' => $ownerType,
                    'owner_code' => $ownerCode,
                    'config_key' => $configKey,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                ])->first();
                return $value?->toArray();
            },
        );
        $stored = is_array($valuePayload) ? (array) ($valuePayload['value_json'] ?? []) : [];

        return [
            'definition' => $this->definitionPayload($definition),
            'scope' => ['type' => $scopeType, 'id' => $scopeId],
            'defaults' => $this->codec->decode((array) $definition->schema_json, []),
            'overrides' => $stored,
            'effective_values' => $this->codec->decode((array) $definition->schema_json, $stored),
            'revision' => is_array($valuePayload) ? (int) ($valuePayload['revision'] ?? 0) : 0,
            'updated_at' => is_array($valuePayload) && !empty($valuePayload['updated_at'])
                ? (int) strtotime((string) $valuePayload['updated_at'])
                : null,
            'checksum' => is_array($valuePayload) ? ($valuePayload['checksum'] ?? null) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function put(string $ownerType, string $ownerCode, string $configKey, array $data, string $scopeType = 'global', string $scopeId = '', ?int $expectedRevision = null): array
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
        $currentRevision = $existing ? (int) $existing->revision : 0;
        if ($expectedRevision !== null && $expectedRevision !== $currentRevision) {
            throw new ConfigException('Configuration value was changed by another request.', 'config_revision_conflict', [
                'expected_revision' => $expectedRevision,
                'actual_revision' => $currentRevision,
            ]);
        }
        try {
            $encoded = $this->codec->encodeOverrides(
                (array) $definition->schema_json,
                $data,
                $existing ? (array) $existing->value_json : [],
            );
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
            'revision' => $currentRevision + 1,
            'updated_at' => $now,
        ]);
        if (!$value->exists) {
            $value->created_at = $now;
        }
        $value->save();
        Cache::forget($this->valueCacheKey($ownerType, $ownerCode, $configKey, $scopeType, $scopeId));

        return $this->get($ownerType, $ownerCode, $configKey, $scopeType, $scopeId);
    }

    private function definition(string $ownerType, string $ownerCode, string $configKey): ConfigDefinition
    {
        $definition = Cache::remember($this->definitionCacheKey($ownerType, $ownerCode, $configKey), 3600, static function () use ($ownerType, $ownerCode, $configKey): ?ConfigDefinition {
            return ConfigDefinition::query()->where([
                'owner_type' => $ownerType,
                'owner_code' => $ownerCode,
                'config_key' => $configKey,
                'status' => true,
            ])->first();
        });
        if (!$definition) {
            throw new ConfigException('Configuration definition was not found.', 'config_not_found', [
                'owner_type' => $ownerType,
                'owner_code' => $ownerCode,
                'config_key' => $configKey,
            ]);
        }

        return $definition;
    }

    private function definitionCacheKey(string $ownerType, string $ownerCode, string $configKey): string
    {
        return 'ptadmin:config:definition:'.$ownerType.':'.$ownerCode.':'.$configKey;
    }

    private function valueCacheKey(string $ownerType, string $ownerCode, string $configKey, string $scopeType, string $scopeId): string
    {
        return 'ptadmin:config:value:'.implode(':', [$ownerType, $ownerCode, $configKey, $scopeType, $scopeId]);
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
            'exposure' => (string) ($definition->exposure ?: 'internal'),
            'activation_mode' => (string) ($definition->activation_mode ?: 'immediate'),
            'schema' => (array) $definition->schema_json,
            'checksum' => (string) $definition->checksum,
            'status' => (bool) $definition->status,
        ];
    }

    private static function schemaName(string $configKey, mixed $name): string
    {
        $candidate = is_string($name) && $name !== '' ? $name : $configKey;
        $normalized = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($candidate));
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/', $normalized) === 1) {
            return $normalized;
        }

        return (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(str_replace(['.', '-'], '_', $configKey)));
    }
}
