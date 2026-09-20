<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use PTAdmin\Core\Dictionary\DictionaryException;
use PTAdmin\Core\Dictionary\DictionaryService;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class DictionaryController extends Controller
{
    public function __construct(private DictionaryService $dictionaries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $input = $this->validate($request->query(), [
            'source' => ['sometimes', 'in:system,plugin,custom'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return AdminResponse::success('', $this->dictionaries->paginate($input));
    }

    public function show(string $code): JsonResponse
    {
        return $this->respond(fn (): array => $this->dictionaries->find($code));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validate($request->all(), [
            'code' => ['required', 'string', 'max:190', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'exposure' => ['required', 'in:internal,public'],
            'value_type' => ['required', 'in:string,number'],
            'extension_policy' => ['sometimes', 'in:editable'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->respond(fn (): array => $this->dictionaries->create($input), 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $input = $this->validate($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'exposure' => ['required', 'in:internal,public'],
            'code' => ['prohibited'],
            'value_type' => ['prohibited'],
            'extension_policy' => ['prohibited'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->respond(fn (): array => $this->dictionaries->update($code, $input));
    }

    public function destroy(string $code): JsonResponse
    {
        return $this->respond(function () use ($code) {
            $this->dictionaries->delete($code);

            return null;
        });
    }

    public function entries(Request $request, string $code): JsonResponse
    {
        $input = $this->validate($request->query(), [
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->respond(fn (): array => $this->dictionaries->paginateItems($code, $input));
    }

    public function storeEntry(Request $request, string $code): JsonResponse
    {
        $input = $this->validate($request->all(), $this->entryRules(true));
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->respond(fn (): array => $this->dictionaries->createItem($code, $input), 201);
    }

    public function updateEntry(Request $request, string $code, int $id): JsonResponse
    {
        $input = $this->validate($request->all(), $this->entryRules(false));
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->respond(fn (): array => $this->dictionaries->updateItem($code, $id, $input));
    }

    public function destroyEntry(string $code, int $id): JsonResponse
    {
        return $this->respond(function () use ($code, $id) {
            $this->dictionaries->deleteItem($code, $id);

            return null;
        });
    }

    /** @return array<string, array<int, string>> */
    private function entryRules(bool $create): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'value' => $create ? ['required'] : ['prohibited'],
            'display' => ['required', 'array'],
            'display.type' => ['required', 'in:default,primary,success,info,warning,danger,custom'],
            'display.background_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'display.text_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'enabled' => ['required', 'boolean'],
            'sort' => ['required', 'integer', 'min:-2147483648', 'max:2147483647'],
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $rules */
    private function validate(array $input, array $rules): array|JsonResponse
    {
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            return AdminResponse::error(42200, '数据字典参数校验失败。', $validator->errors()->toArray(), 422);
        }

        return $validator->validated();
    }

    /** @param callable(): mixed $operation */
    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return AdminResponse::success('', $operation(), $status);
        } catch (DictionaryException $exception) {
            return AdminResponse::error(
                $exception->status === 404 ? 40400 : ($exception->status === 403 ? 40300 : ($exception->status === 409 ? 40900 : 42200)),
                $exception->getMessage(),
                ['error_code' => $exception->errorCode] + $exception->context,
                $exception->status,
            );
        }
    }
}
