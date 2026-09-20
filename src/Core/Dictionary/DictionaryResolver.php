<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use PTAdmin\Core\Dictionary\Models\DictionaryItem;
use PTAdmin\Schema\Contracts\DictionaryResolverInterface;

/** Schema Runtime 的数据库字典解析实现。 */
final class DictionaryResolver implements DictionaryResolverInterface
{
    public function __construct(private DictionaryService $dictionaries)
    {
    }

    public function candidates(string $code, array $query, string $fieldType): array
    {
        try {
            $definition = $this->dictionaries->definitionModel($code);
            $this->assertCompatible((string) $definition->value_type, $fieldType);
            $page = max(1, (int) ($query['page'] ?? 1));
            $limit = max(1, min(200, (int) ($query['limit'] ?? 20)));
            $items = DictionaryItem::query()->where([
                'dict_id' => $definition->getKey(),
                'enabled' => true,
            ]);
            $keyword = trim((string) ($query['keyword'] ?? ''));
            if ($keyword !== '') {
                $items->where(static function ($nested) use ($keyword): void {
                    $nested->where('label', 'like', '%'.$keyword.'%')
                        ->orWhere('value', 'like', '%'.$keyword.'%');
                });
            }
            $total = (clone $items)->count();
            $results = $items->orderBy('sort')->orderBy('id')->forPage($page, $limit)->get()
                ->map(fn (DictionaryItem $item): array => $this->candidate($item, (string) $definition->value_type, false))
                ->all();
            $selected = [];
            foreach ((array) ($query['values'] ?? []) as $selectedValue) {
                $normalized = $this->dictionaries->normalizeValue($selectedValue, (string) $definition->value_type);
                $item = DictionaryItem::withTrashed()
                    ->where('dict_id', $definition->getKey())
                    ->where('value', $normalized)
                    ->first();
                if ($item instanceof DictionaryItem) {
                    $selected[] = $this->candidate($item, (string) $definition->value_type, !$item->enabled || $item->trashed());
                }
            }

            return [
                'results' => $results,
                'selected' => $selected,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'has_more' => $total > $page * $limit,
            ];
        } catch (DictionaryException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), previous: $exception);
        }
    }

    public function assertAllowed(string $code, mixed $value, bool $multiple, string $fieldType): void
    {
        try {
            $definition = $this->dictionaries->definitionModel($code);
            $this->assertCompatible((string) $definition->value_type, $fieldType);
            $values = $multiple && is_array($value) ? $value : [$value];
            foreach ($values as $candidate) {
                $normalized = $this->dictionaries->normalizeValue($candidate, (string) $definition->value_type);
                if (!DictionaryItem::query()->where([
                    'dict_id' => $definition->getKey(),
                    'value' => $normalized,
                    'enabled' => true,
                ])->exists()) {
                    throw new DictionaryException('字段值不属于当前字典的启用条目。', 'dictionary_value_not_allowed');
                }
            }
        } catch (DictionaryException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), previous: $exception);
        }
    }

    private function assertCompatible(string $valueType, string $fieldType): void
    {
        $compatible = $valueType === 'string'
            ? $fieldType === 'string'
            : in_array($fieldType, ['number', 'integer'], true);
        if (!$compatible) {
            throw new DictionaryException('Schema 字段类型与字典值类型不匹配。', 'dictionary_value_type_mismatch');
        }
    }

    /** @return array<string, mixed> */
    private function candidate(DictionaryItem $item, string $valueType, bool $disabled): array
    {
        return [
            'value' => $this->dictionaries->outputValue((string) $item->value, $valueType),
            'label' => (string) $item->label,
            'description' => '',
            'disabled' => $disabled,
            'display' => (array) ($item->display_json ?? ['type' => 'default']),
        ];
    }
}
