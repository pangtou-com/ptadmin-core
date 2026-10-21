<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Responses;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Core\Http\Middleware\RequestIdMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Laravel 异常使用 Admin API 协议，公开网页仍交由宿主 Handler 处理。 */
final class AdminExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        $prefix = trim((string) config('ptadmin.api_prefix', 'ptadmin'), '/');
        if (!$request->is($prefix, $prefix.'/*') || $exception instanceof HttpResponseException) {
            return null;
        }
        $status = 500;
        $message = '服务器内部错误，请稍后重试。';
        $data = [];
        $headers = [];
        if ($exception instanceof ValidationException) {
            $status = $exception->status;
            $message = $exception->getMessage();
            $data = ['errors' => $exception->errors()];
        } elseif ($exception instanceof AuthenticationException) {
            $status = 401;
            $message = '请先登录。';
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = $exception->getHeaders();
            $message = match ($status) {
                404 => '请求的接口或资源不存在。',
                405 => '请求方法不受支持。',
                default => $status >= 500 ? $message : ($exception->getMessage() ?: (Response::$statusTexts[$status] ?? '请求失败。')),
            };
        } elseif ($exception instanceof AddonException && in_array($exception->errorCode, ['addon_catalog_invalid', 'addon_catalog_unavailable'], true)) {
            $status = 503;
            $message = '插件注册信息不可用，请联系管理员重建插件缓存。';
            $data = ['error_code' => $exception->errorCode];
        }
        $response = AdminResponse::error($status * 100, $message, $data, $status)->withHeaders($headers);
        return (new RequestIdMiddleware())->handle($request, static fn (): Response => $response);
    }
}
