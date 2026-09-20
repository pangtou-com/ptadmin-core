<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PTAdmin\Core\Dictionary\Models\DictionaryDefinition;
use PTAdmin\Core\Dictionary\Models\DictionaryItem;

/** 数据字典定义、条目和后台可写边界的唯一领域服务。 */
final class DictionaryService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $query = DictionaryDefinition::query()->where('status', true);
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $query->where('source', $source);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function (Builder $nested) use ($keyword): void {
                $nested->where('title', 'like', '%'.$keyword.'%')
                    ->orWhere('code', 'like', '%'.$keyword.'%')
                    ->orWhere('description', 'like', '%'.$keyword.'%');
            });
        }
        $total = (clone $query)->count();
        $definitions = $query->orderByRaw("case source when 'system' then 1 when 'plugin' then 2 else 3 end")
            ->orderBy('id')
            ->forPage($page, $limit)
            ->get();
        $counts = DictionaryItem::query()
            ->whereIn('dict_id', $definitions->pluck('id'))
            ->selectRaw('dict_id, count(*) as aggregate')
            ->groupBy('dict_id')
            ->pluck('aggregate', 'dict_id');

        return [
            'results' => $definitions->map(fn (DictionaryDefinition $definition): array => $this->serializeDefinition(
                $definition,
                (int) ($counts[$definition->getKey()] ?? 0),
            ))->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function find(string $code): array
    {
        $definition = $this->definition($code);

        return $this->serializeDefinition($definition, $this->itemQuery($definition)->count());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if ($code === '' || preg_match('/^[a-z][a-z0-9_.-]{0,189}$/', $code) !== 1) {
            throw new DictionaryException('字典编码格式无效。', 'dictionary_code_invalid');
        }
        if (trim((string) ($input['title'] ?? '')) === '') {
            throw new DictionaryException('字典名称不能为空。', 'dictionary_title_invalid');
        }
        if (!in_array(($input['exposure'] ?? 'internal'), ['internal', 'public'], true)) {
            throw new DictionaryException('字典读取范围无效。', 'dictionary_exposure_invalid');
        }
        if (!in_array(($input['value_type'] ?? 'string'), ['string', 'number'], true)) {
            throw new DictionaryException('字典值类型无效。', 'dictionary_value_type_invalid');
        }
        if (DictionaryDefinition::withTrashed()->where('code', $code)->exists()) {
            throw new DictionaryException('字典编码已被使用，删除后的编码也不能复用。', 'dictionary_code_exists', 409);
        }
        $definition = new DictionaryDefinition();
        $definition->fill([
            'code' => $code,
            'title' => trim((string) ($input['title'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'source' => 'custom',
            'owner_code' => '',
            'exposure' => (string) ($input['exposure'] ?? 'internal'),
            'value_type' => (string) ($input['value_type'] ?? 'string'),
            'extension_policy' => 'editable',
            'version' => 1,
            'status' => true,
        ]);
        $definition->save();

        return $this->serializeDefinition($definition, 0);
    }

    /** @param array<string, mixed> $input */
    public function update(string $code, array $input): array
    {
        $definition = $this->definition($code);
        if ($definition->source !== 'custom') {
            throw new DictionaryException('代码声明的字典定义不能在后台修改。', 'dictionary_locked', 403);
        }
        $expected = (int) ($input['expected_version'] ?? 0);
        if ($expected !== (int) $definition->version) {
            throw new DictionaryException('字典已被其他管理员修改，请刷新后重试。', 'dictionary_version_conflict', 409);
        }
        if (trim((string) ($input['title'] ?? $definition->title)) === '') {
            throw new DictionaryException('字典名称不能为空。', 'dictionary_title_invalid');
        }
        if (!in_array(($input['exposure'] ?? $definition->exposure), ['internal', 'public'], true)) {
            throw new DictionaryException('字典读取范围无效。', 'dictionary_exposure_invalid');
        }
        $definition->fill([
            'title' => trim((string) ($input['title'] ?? $definition->title)),
            'description' => trim((string) ($input['description'] ?? $definition->description)),
            'exposure' => (string) ($input['exposure'] ?? $definition->exposure),
            'extension_policy' => 'editable',
            'version' => (int) $definition->version + 1,
        ]);
        $definition->save();

        return $this->serializeDefinition($definition, $this->itemQuery($definition)->count());
    }

    public function delete(string $code): void
    {
        $definition = $this->definition($code);
        if ($definition->source !== 'custom') {
            throw new DictionaryException('代码声明的字典不能删除。', 'dictionary_locked', 403);
        }
        DB::transaction(function () use ($definition): void {
            foreach ($this->itemQuery($definition)->get() as $item) {
                $item->delete();
            }
            $definition->delete();
        });
    }

    /** @param array<string, mixed> $filters */
    public function paginateItems(string $code, array $filters = []): array
    {
        $definition = $this->definition($code);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $query = $this->itemQuery($definition);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function (Builder $nested) use ($keyword): void {
                $nested->where('label', 'like', '%'.$keyword.'%')
                    ->orWhere('value', 'like', '%'.$keyword.'%');
            });
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('sort')->orderBy('id')->forPage($page, $limit)->get();

        return [
            'results' => $items->map(fn (DictionaryItem $item): array => $this->serializeItem($item, $definition))->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @param array<string, mixed> $input */
    public function createItem(string $code, array $input): array
    {
        $definition = $this->definition($code);
        if ($definition->extension_policy === 'closed') {
            throw new DictionaryException('当前字典不允许追加自定义条目。', 'dictionary_closed', 403);
        }
        if (trim((string) ($input['label'] ?? '')) === '') {
            throw new DictionaryException('字典条目名称不能为空。', 'dictionary_item_label_invalid');
        }
        $value = $this->normalizeValue($input['value'] ?? null, (string) $definition->value_type);
        if (DictionaryItem::withTrashed()->where('dict_id', $definition->getKey())->where('value', $value)->exists()) {
            throw new DictionaryException('选项值已被使用，删除后的值也不能复用。', 'dictionary_item_value_exists', 409);
        }
        $item = new DictionaryItem();
        $item->fill([
            'dict_id' => $definition->getKey(),
            'label' => trim((string) ($input['label'] ?? '')),
            'value' => $value,
            'display_json' => $this->normalizeDisplay((array) ($input['display'] ?? [])),
            'source' => 'custom',
            'owner_code' => '',
            'enabled' => (bool) ($input['enabled'] ?? true),
            'sort' => (int) ($input['sort'] ?? 0),
        ]);
        $item->save();

        return $this->serializeItem($item, $definition);
    }

    /** @param array<string, mixed> $input */
    public function updateItem(string $code, int $id, array $input): array
    {
        $definition = $this->definition($code);
        $item = $this->item($definition, $id);
        $this->assertItemWritable($definition, $item);
        if (trim((string) ($input['label'] ?? $item->label)) === '') {
            throw new DictionaryException('字典条目名称不能为空。', 'dictionary_item_label_invalid');
        }
        $item->fill([
            'label' => trim((string) ($input['label'] ?? $item->label)),
            'display_json' => $this->normalizeDisplay((array) ($input['display'] ?? $item->display_json ?? [])),
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : (bool) $item->enabled,
            'sort' => array_key_exists('sort', $input) ? (int) $input['sort'] : (int) $item->sort,
        ]);
        $item->save();

        return $this->serializeItem($item, $definition);
    }

    public function deleteItem(string $code, int $id): void
    {
        $definition = $this->definition($code);
        $item = $this->item($definition, $id);
        $this->assertItemWritable($definition, $item);
        $item->delete();
    }

    public function definitionModel(string $code, bool $requirePublic = false): DictionaryDefinition
    {
        $definition = $this->definition($code);
        if ($requirePublic && $definition->exposure !== 'public') {
            throw new DictionaryException('公开字典不存在。', 'dictionary_not_found', 404);
        }

        return $definition;
    }

    public function normalizeValue(mixed $value, string $valueType): string
    {
        if ($valueType === 'number') {
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                throw new DictionaryException('数字字典的选项值必须是有效数字。', 'dictionary_value_invalid');
            }
            $numeric = (float) $value;
            if (!is_finite($numeric)) {
                throw new DictionaryException('数字字典的选项值必须是有限数字。', 'dictionary_value_invalid');
            }

            return floor($numeric) === $numeric ? (string) (int) $numeric : rtrim(rtrim(sprintf('%.14F', $numeric), '0'), '.');
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new DictionaryException('字符串字典的选项值无效。', 'dictionary_value_invalid');
        }
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new DictionaryException('字典选项值不能为空。', 'dictionary_value_invalid');
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function serializeDefinition(DictionaryDefinition $definition, ?int $entryCount = null): array
    {
        return [
            'id' => (int) $definition->getKey(),
            'code' => (string) $definition->code,
            'title' => (string) $definition->title,
            'description' => (string) ($definition->description ?? ''),
            'source' => (string) $definition->source,
            'owner_code' => (string) $definition->owner_code,
            'exposure' => (string) $definition->exposure,
            'value_type' => (string) $definition->value_type,
            'version' => (int) $definition->version,
            'extension_policy' => (string) $definition->extension_policy,
            'entry_count' => $entryCount ?? $this->itemQuery($definition)->count(),
            'created_at' => (int) $definition->getRawOriginal('created_at'),
            'updated_at' => (int) $definition->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeItem(DictionaryItem $item, DictionaryDefinition $definition): array
    {
        return [
            'id' => (int) $item->getKey(),
            'dictionary_id' => (int) $item->dict_id,
            'label' => (string) $item->label,
            'value' => $this->outputValue((string) $item->value, (string) $definition->value_type),
            'display' => $this->normalizeDisplay((array) ($item->display_json ?? [])),
            'source' => (string) $item->source,
            'locked' => $item->source !== 'custom',
            'enabled' => (bool) $item->enabled,
            'sort' => (int) $item->sort,
            'created_at' => (int) $item->getRawOriginal('created_at'),
            'updated_at' => (int) $item->getRawOriginal('updated_at'),
        ];
    }

    public function outputValue(string $value, string $valueType): string|int|float
    {
        if ($valueType !== 'number') {
            return $value;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function definition(string $code): DictionaryDefinition
    {
        $definition = DictionaryDefinition::query()->where(['code' => strtolower(trim($code)), 'status' => true])->first();
        if (!$definition instanceof DictionaryDefinition) {
            throw new DictionaryException('数据字典不存在或已停用。', 'dictionary_not_found', 404);
        }

        return $definition;
    }

    private function itemQuery(DictionaryDefinition $definition): Builder
    {
        return DictionaryItem::query()->where('dict_id', $definition->getKey());
    }

    private function item(DictionaryDefinition $definition, int $id): DictionaryItem
    {
        $item = $this->itemQuery($definition)->find($id);
        if (!$item instanceof DictionaryItem) {
            throw new DictionaryException('字典条目不存在。', 'dictionary_item_not_found', 404);
        }

        return $item;
    }

    private function assertItemWritable(DictionaryDefinition $definition, DictionaryItem $item): void
    {
        if ($definition->extension_policy === 'closed' || $item->source !== 'custom') {
            throw new DictionaryException('代码声明的字典条目不能在后台修改。', 'dictionary_item_locked', 403);
        }
    }

    /** @param array<string, mixed> $display @return array<string, mixed> */
    private function normalizeDisplay(array $display): array
    {
        $type = (string) ($display['type'] ?? 'default');
        $result = ['type' => in_array($type, ['default', 'primary', 'success', 'info', 'warning', 'danger', 'custom'], true) ? $type : 'default'];
        if ($result['type'] === 'custom') {
            $result['background_color'] = (string) ($display['background_color'] ?? '#eef2ff');
            $result['text_color'] = (string) ($display['text_color'] ?? '#334155');
        }

        return $result;
    }
}
