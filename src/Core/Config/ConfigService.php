<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Core\Config\Models\ConfigValue;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Support\FieldProtection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Throwable;

/**
 * 配置定义和值的统一服务。值按 global 作用域保存为一条 JSON 对象记录。
 */
final class ConfigService
{
    public function __construct(private ConfigValueCodec $codec)
    {
    }

    /** 通过配置组与字段路径读取生效值，默认读取 Core 配置，例如 site.url。 */
    public function value(string $path, string $ownerType = 'core', string $ownerCode = 'ptadmin'): mixed
    {
        $segments = explode('.', $path, 2);
        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new ConfigException('Configuration path must contain a group and field: '.$path, 'config_path_invalid');
        }
        $configuration = $this->get($ownerType, $ownerCode, $segments[0]);

        return Arr::get($configuration['effective_values'], $segments[1]);
    }

    /** 服务端专用：读取并解密指定凭据，不暴露给配置 HTTP 接口。 */
    public function secretValue(string $path, string $ownerType = 'core', string $ownerCode = 'ptadmin'): ?string
    {
        $segments = explode('.', $path, 2);
        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new ConfigException('Configuration path must contain a group and field: '.$path, 'config_path_invalid');
        }
        $definition = $this->definition($ownerType, $ownerCode, $segments[0]);
        foreach ((array) ($definition->schema_json['fields'] ?? []) as $field) {
            if ($field['name'] !== $segments[1]) {
                continue;
            }
            if (FieldProtection::mode($field) !== 'encrypted') {
                throw new ConfigException('Configuration field is not encrypted: '.$path, 'config_secret_invalid');
            }
            $value = ConfigValue::query()->where([
                'owner_type' => $ownerType, 'owner_code' => $ownerCode, 'config_key' => $segments[0],
                'scope_type' => 'global', 'scope_id' => '',
            ])->first();
            return FieldProtection::reveal($value?->value_json[$segments[1]] ?? null, $field);
        }
        throw new ConfigException('Configuration secret field was not found: '.$path, 'config_secret_invalid');
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
        if (!in_array(data_get($schemaData, 'extensions.ptadmin.configuration.management', 'generic'), ['generic', 'dedicated'], true)) {
            throw new ConfigException('Configuration management must be generic or dedicated.', 'config_definition_invalid');
        }
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
        if ($definition->exists) {
            $previousFields = array_column((array) ($definition->schema_json['fields'] ?? []), null, 'name');
            foreach ($normalized['fields'] as $field) {
                $previousMode = FieldProtection::mode($previousFields[$field['name']] ?? []);
                $nextMode = FieldProtection::mode($field);
                if ($previousMode === $nextMode || !in_array('encrypted', [$previousMode, $nextMode], true)) {
                    continue;
                }
                $values = ConfigValue::query()->where([
                    'owner_type' => $ownerType, 'owner_code' => $ownerCode, 'config_key' => $configKey,
                ])->get();
                foreach ($values as $value) {
                    if (array_key_exists($field['name'], (array) $value->getAttribute('value_json'))) {
                        throw new ConfigException('Configuration secret storage changes require a data migration: '.$configKey.'.'.$field['name'], 'config_secret_migration_required');
                    }
                }
            }
        }
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
        $this->forgetAfterCommit($this->definitionCacheKey($ownerType, $ownerCode, $configKey));
        $this->forgetAfterCommit($this->valueCacheKey($ownerType, $ownerCode, $configKey, 'global', ''));

        return $this->definitionPayload($definition);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(array $filters = [], bool $includeDedicated = true): array
    {
        $query = ConfigDefinition::query()->where('status', true)->orderBy('group_code')->orderBy('id');
        foreach (['owner_type', 'owner_code', 'group_code', 'config_key'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        return $query->get()
            ->filter(static fn (ConfigDefinition $definition): bool => $includeDedicated || data_get($definition->schema_json, 'extensions.ptadmin.configuration.management') !== 'dedicated')
            ->map(fn (ConfigDefinition $definition): array => $this->definitionPayload($definition))->values()->all();
    }

    /** 通用 HTTP 配置入口不能绕过专用页面的业务校验与权限。 */
    public function assertGenericAccess(string $ownerType, string $ownerCode, string $configKey): void
    {
        $definition = $this->definition($ownerType, $ownerCode, $configKey);
        if (data_get($definition->schema_json, 'extensions.ptadmin.configuration.management') === 'dedicated') {
            throw new ConfigException('请在对应业务页面管理此配置。', 'config_dedicated_editor_required');
        }
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
        $this->forgetAfterCommit($this->definitionCacheKey($ownerType, $ownerCode, $configKey));

        return $this->definitionPayload($definition);
    }

    /** @return array<string, mixed> */
    public function get(string $ownerType, string $ownerCode, string $configKey, string $scopeType = 'global', string $scopeId = ''): array
    {
        $this->assertScope($scopeType, $scopeId);
        $definition = $this->definition($ownerType, $ownerCode, $configKey);
        $valuePayload = $this->rememberOutsideTransaction(
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
            'overrides' => $this->codec->decode((array) $definition->schema_json, $stored, false),
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
        return DB::transaction(function () use ($ownerType, $ownerCode, $configKey, $data, $scopeType, $scopeId, $expectedRevision): array {
            $definition = $this->definition($ownerType, $ownerCode, $configKey);
            $this->assertScope($scopeType, $scopeId);
            // 定义行始终存在，首次写入也能串行校验 revision，避免丢失并发更新。
            ConfigDefinition::query()->whereKey($definition->id)->lockForUpdate()->first();
            $existing = ConfigValue::query()->where([
                'owner_type' => $ownerType,
                'owner_code' => $ownerCode,
                'config_key' => $configKey,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ])->lockForUpdate()->first();
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
            $this->forgetAfterCommit($this->valueCacheKey($ownerType, $ownerCode, $configKey, $scopeType, $scopeId));

            return $this->get($ownerType, $ownerCode, $configKey, $scopeType, $scopeId);
        });
    }

    private function definition(string $ownerType, string $ownerCode, string $configKey): ConfigDefinition
    {
        $definition = $this->rememberOutsideTransaction($this->definitionCacheKey($ownerType, $ownerCode, $configKey), 3600, static function () use ($ownerType, $ownerCode, $configKey): ?ConfigDefinition {
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

    /** 事务中的配置不得写入共享缓存，避免回滚后仍读到未提交值。 */
    private function rememberOutsideTransaction(string $key, int $ttl, callable $load): mixed
    {
        return DB::connection()->transactionLevel() > 0 ? $load() : Cache::remember($key, $ttl, $load);
    }

    private function forgetAfterCommit(string $key): void
    {
        Cache::forget($key);
        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit(static fn () => Cache::forget($key));
        }
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
