<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Dictionary\DictionaryException;
use PTAdmin\Core\Dictionary\DictionaryService;
use PTAdmin\Core\Dictionary\Models\DictionaryItem;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class PublicDictionaryController extends Controller
{
    public function __construct(private DictionaryService $dictionaries)
    {
    }

    public function show(string $code): JsonResponse
    {
        try {
            $definition = $this->dictionaries->definitionModel($code, true);
            $entries = DictionaryItem::query()->where([
                'dict_id' => $definition->getKey(),
                'enabled' => true,
            ])->orderBy('sort')->orderBy('id')->get()->map(fn (DictionaryItem $item): array => [
                'label' => (string) $item->label,
                'value' => $this->dictionaries->outputValue((string) $item->value, (string) $definition->value_type),
                'display' => (array) ($item->display_json ?? ['type' => 'default']),
            ])->all();

            return AdminResponse::success('', [
                'code' => (string) $definition->code,
                'title' => (string) $definition->title,
                'value_type' => (string) $definition->value_type,
                'entries' => $entries,
            ]);
        } catch (DictionaryException $exception) {
            return AdminResponse::error(40400, '公开字典不存在。', ['error_code' => 'dictionary_not_found'], 404);
        }
    }
}
