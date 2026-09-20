<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use Illuminate\Support\Facades\DB;
use PTAdmin\Core\Dictionary\Contracts\DictionaryProviderInterface;
use PTAdmin\Core\Dictionary\Models\DictionaryDefinition;
use PTAdmin\Core\Dictionary\Models\DictionaryItem;

/** 将 PHP 链式字典声明幂等投影到数据库。 */
final class DictionarySynchronizer
{
    public function __construct(
        private DictionaryService $dictionaries,
        private CoreDictionaryProvider $coreProvider,
    ) {
    }

    public function syncCore(): int
    {
        return $this->syncProvider($this->coreProvider, 'system', 'ptadmin');
    }

    public function syncAddon(string $directory, string $ownerCode): int
    {
        $provider = $this->loadAddonProvider($directory);
        if (!$provider instanceof DictionaryProviderInterface) {
            $this->deactivate('plugin', $ownerCode);

            return 0;
        }

        return $this->syncProvider($provider, 'plugin', strtolower($ownerCode));
    }

    /** @param array<int, array<string, mixed>> $definitions */
    public function syncDefinitions(array $definitions, string $source, string $ownerCode): int
    {
        return $this->sync($definitions, $source, strtolower($ownerCode));
    }

    public function deactivate(string $source, string $ownerCode): void
    {
        DictionaryDefinition::query()->where([
            'source' => $source,
            'owner_code' => strtolower($ownerCode),
        ])->update(['status' => false]);
    }

    private function syncProvider(DictionaryProviderInterface $provider, string $source, string $ownerCode): int
    {
        $registry = new DictionaryRegistry($source, $ownerCode);
        $provider->register($registry);

        return $this->sync($registry->definitions(), $source, $ownerCode);
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function sync(array $definitions, string $source, string $ownerCode): int
    {
        return DB::transaction(function () use ($definitions, $source, $ownerCode): int {
            $codes = [];
            foreach ($definitions as $input) {
                $code = strtolower(trim((string) ($input['code'] ?? '')));
                $this->assertDefinition($input, $code, $source);
                if (isset($codes[$code])) {
                    throw new DictionaryException('字典声明中存在重复编码：'.$code, 'dictionary_declaration_duplicate');
                }
                $codes[$code] = true;
                $this->syncDefinition($input, $code, $source, $ownerCode);
            }

            $stale = DictionaryDefinition::query()->where(['source' => $source, 'owner_code' => $ownerCode]);
            if ($codes !== []) {
                $stale->whereNotIn('code', array_keys($codes));
            }
            $stale->update(['status' => false]);

            return count($definitions);
        });
    }

    /** @param array<string, mixed> $input */
    private function syncDefinition(array $input, string $code, string $source, string $ownerCode): void
    {
        $existing = DictionaryDefinition::withTrashed()->where('code', $code)->first();
        if ($existing?->trashed()) {
            throw new DictionaryException('字典编码已被删除记录占用：'.$code, 'dictionary_code_exists', 409);
        }
        if ($existing && ($existing->source !== $source || $existing->owner_code !== $ownerCode)) {
            throw new DictionaryException('字典编码已属于其他来源：'.$code, 'dictionary_owner_conflict', 409);
        }
        $definition = $existing ?? new DictionaryDefinition();
        $attributes = [
            'code' => $code,
            'title' => trim((string) ($input['title'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'source' => $source,
            'owner_code' => $ownerCode,
            'exposure' => (string) ($input['exposure'] ?? 'internal'),
            'value_type' => (string) ($input['value_type'] ?? 'string'),
            'extension_policy' => (string) ($input['extension_policy'] ?? 'closed'),
            'status' => true,
        ];
        if ($definition->exists && $definition->value_type !== $attributes['value_type'] && DictionaryItem::withTrashed()->where('dict_id', $definition->getKey())->exists()) {
            throw new DictionaryException('已有条目的代码字典不能修改值类型：'.$code, 'dictionary_value_type_locked');
        }
        $changed = !$definition->exists || array_filter(
            $attributes,
            static fn (mixed $value, string $key): bool => (string) $definition->getAttribute($key) !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        ) !== [];
        $definition->fill($attributes);
        $definition->version = $definition->exists ? (int) $definition->version + ($changed ? 1 : 0) : 1;
        $definition->save();

        $itemValues = [];
        foreach ((array) ($input['entries'] ?? []) as $itemInput) {
            if (!is_array($itemInput) || array_is_list($itemInput)) {
                throw new DictionaryException('字典条目声明必须是对象：'.$code, 'dictionary_declaration_invalid');
            }
            $itemValue = $this->dictionaries->normalizeValue($itemInput['value'] ?? null, (string) $definition->value_type);
            $identity = 'value:'.$itemValue;
            if (isset($itemValues[$identity])) {
                throw new DictionaryException('字典选项值重复：'.$code.'.'.$itemValue, 'dictionary_declaration_invalid');
            }
            if (trim((string) ($itemInput['label'] ?? '')) === '') {
                throw new DictionaryException('字典条目名称不能为空：'.$code.'.'.$itemValue, 'dictionary_declaration_invalid');
            }
            $itemValues[$identity] = $itemValue;
            $this->syncItem($definition, $itemInput, $itemValue, $source, $ownerCode);
        }

        $removed = DictionaryItem::query()->where([
            'dict_id' => $definition->getKey(),
            'source' => $source,
            'owner_code' => $ownerCode,
        ]);
        if ($itemValues !== []) {
            $removed->whereNotIn('value', array_values($itemValues));
        }
        $removed->update(['enabled' => false]);
    }

    /** @param array<string, mixed> $input */
    private function syncItem(DictionaryDefinition $definition, array $input, string $value, string $source, string $ownerCode): void
    {
        $item = DictionaryItem::withTrashed()->where('dict_id', $definition->getKey())->where('value', $value)->first();
        if ($item?->trashed()) {
            throw new DictionaryException('字典选项值已被删除记录占用：'.$definition->code.'.'.$value, 'dictionary_item_value_exists', 409);
        }
        if ($item && ($item->source !== $source || $item->owner_code !== $ownerCode)) {
            throw new DictionaryException('字典选项值已属于后台自定义数据：'.$definition->code.'.'.$value, 'dictionary_item_owner_conflict', 409);
        }
        $item ??= new DictionaryItem();
        $item->fill([
            'dict_id' => $definition->getKey(),
            'label' => trim((string) ($input['label'] ?? '')),
            'value' => $value,
            'display_json' => (array) ($input['display'] ?? ['type' => 'default']),
            'source' => $source,
            'owner_code' => $ownerCode,
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true,
            'sort' => (int) ($input['sort'] ?? 0),
        ]);
        $item->save();
    }

    /** @param array<string, mixed> $input */
    private function assertDefinition(array $input, string $code, string $source): void
    {
        if ($code === '' || preg_match('/^[a-z][a-z0-9_.-]{0,189}$/', $code) !== 1) {
            throw new DictionaryException('字典声明编码无效。', 'dictionary_declaration_invalid');
        }
        if (trim((string) ($input['title'] ?? '')) === '') {
            throw new DictionaryException('字典声明名称不能为空：'.$code, 'dictionary_declaration_invalid');
        }
        if (!in_array(($input['value_type'] ?? 'string'), ['string', 'number'], true)) {
            throw new DictionaryException('字典值类型无效：'.$code, 'dictionary_declaration_invalid');
        }
        if (!in_array(($input['exposure'] ?? 'internal'), ['internal', 'public'], true)) {
            throw new DictionaryException('字典读取范围无效：'.$code, 'dictionary_declaration_invalid');
        }
        if (!in_array(($input['extension_policy'] ?? 'closed'), ['closed', 'append'], true) || $source === 'custom') {
            throw new DictionaryException('代码字典扩展策略只能是 closed 或 append：'.$code, 'dictionary_declaration_invalid');
        }
    }

    private function loadAddonProvider(string $directory): ?DictionaryProviderInterface
    {
        $path = rtrim($directory, '/').'/Resources/DictionaryProvider.php';
        if (!is_file($path)) {
            return null;
        }
        $manifest = json_decode((string) @file_get_contents(rtrim($directory, '/').'/manifest.json'), true);
        $namespace = is_array($manifest) ? trim((string) ($manifest['namespace'] ?? ''), '\\') : '';
        $class = $namespace !== '' ? $namespace.'\\DictionaryProvider' : '';
        if ($class === '' || !class_exists($class)) {
            require_once $path;
        }
        if ($class === '' || !class_exists($class)) {
            throw new DictionaryException('插件字典 Provider 类不存在。', 'dictionary_provider_invalid');
        }
        $provider = app()->make($class);
        if (!$provider instanceof DictionaryProviderInterface) {
            throw new DictionaryException('插件字典 Provider 必须实现 DictionaryProviderInterface。', 'dictionary_provider_invalid');
        }

        return $provider;
    }
}
