<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use PTAdmin\Schema\Contract\SchemaError;

/**
 * Schema 管理 API 的统一响应边界；业务规则仍由包内服务负责。
 */
abstract class Controller extends BaseController
{
    /** @param callable(): mixed $operation */
    protected function respond(callable $operation): JsonResponse
    {
        try {
            $result = $operation();
            $data = $result instanceof \JsonSerializable ? $result->jsonSerialize() : $result;

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $data,
            ]);
        } catch (SchemaError $error) {
            $status = $error->getSchemaCode() === 'forbidden' ? 403 : 422;
            return response()->json([
                'code' => $status === 403 ? 40300 : 42200,
                'message' => $error->getMessage(),
                'data' => $error->jsonSerialize(),
            ], $status);
        } catch (\TypeError|\InvalidArgumentException $error) {
            $schemaError = new SchemaError('invalid_input', $error->getMessage());

            return response()->json([
                'code' => 42200,
                'message' => $schemaError->getMessage(),
                'data' => $schemaError->jsonSerialize(),
            ], 422);
        }
    }
}
