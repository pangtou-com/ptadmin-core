<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use PTAdmin\Admin\Assets\AssetException;
use PTAdmin\Admin\Assets\AssetService;
use PTAdmin\Admin\Assets\AssetUploadService;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class AssetController extends Controller
{
    public function __construct(
        private AssetService $assets,
        private AssetUploadService $uploads,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mime' => ['sometimes', 'nullable', 'string', 'max:100'],
            'include_deleted' => ['sometimes', 'in:0,1,true,false'],
            'only_deleted' => ['sometimes', 'in:0,1,true,false'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return AdminResponse::success('', $this->assets->paginate($validator->validated()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'include_deleted' => ['sometimes', 'in:0,1,true,false'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            return AdminResponse::success('', $this->assets->find(
                $id,
                filter_var($request->query('include_deleted'), FILTER_VALIDATE_BOOLEAN),
            ));
        } catch (AssetException $exception) {
            return $this->assetError($exception);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $maximum = $this->uploads->maxUploadKilobytes();
        $validator = Validator::make(array_merge($request->all(), ['file' => $request->file('file')]), [
            'file' => ['required', 'file', 'max:'.$maximum],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group_code' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return AdminResponse::error(42200, '请上传有效文件。', ['file' => ['请上传有效文件。']], 422);
        }
        try {
            $asset = $this->uploads->uploadPublic(
                $file,
                (string) $request->input('title', ''),
                (string) $request->input('group_code', 'default'),
            );
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error(42200, $exception->getMessage(), [
                'error_code' => 'asset_upload_invalid',
            ], 422);
        } catch (\Throwable) {
            return AdminResponse::error(50001, '资源上传失败。', [
                'error_code' => 'asset_upload_failed',
            ], 500);
        }

        return AdminResponse::success('', $this->assets->serialize($asset), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'group_code' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            return AdminResponse::success('', $this->assets->update(
                $id,
                (string) $validator->validated()['title'],
            ));
        } catch (AssetException $exception) {
            return $this->assetError($exception);
        }
    }

    public function archive(int $id): JsonResponse
    {
        try {
            $this->assets->archive($id);
        } catch (AssetException $exception) {
            return $this->assetError($exception);
        }

        return AdminResponse::success();
    }

    public function restore(int $id): JsonResponse
    {
        try {
            $this->assets->restore($id);
        } catch (AssetException $exception) {
            return $this->assetError($exception);
        }

        return AdminResponse::success();
    }

    /** @param array<string, mixed> $errors */
    private function validationError(array $errors): JsonResponse
    {
        return AdminResponse::error(42200, '资源参数校验失败。', $errors, 422);
    }

    private function assetError(AssetException $exception): JsonResponse
    {
        return AdminResponse::error(
            $exception->status === 404 ? 40400 : 42200,
            $exception->getMessage(),
            ['error_code' => $exception->errorCode],
            $exception->status,
        );
    }
}
