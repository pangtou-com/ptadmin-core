<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Responses;

use Illuminate\Http\JsonResponse;

final class AdminResponse
{
    /**
     * @param mixed $data
     */
    public static function success(string $message = '', mixed $data = null, int $status = 200): JsonResponse
    {
        $payload = [
            'code' => 0,
            'message' => $message,
        ];

        if (null !== $data) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param mixed $data
     */
    public static function error(int $code, string $message, mixed $data = null, int $status = 400): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $message,
        ];

        if (null !== $data) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }
}
