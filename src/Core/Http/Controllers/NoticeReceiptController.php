<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use PTAdmin\Notice\NoticeDeliveryService;
use PTAdmin\Notice\NoticeChannelAdapterRegistry;
use PTAdmin\Notice\Contracts\NoticeChannelReceiptAuthenticatorInterface;
use PTAdmin\Notice\NoticeSendingException;
use Throwable;

final class NoticeReceiptController extends Controller
{
    public function __construct(private readonly NoticeDeliveryService $deliveries, private readonly NoticeChannelAdapterRegistry $adapters)
    {
    }

    public function __invoke(Request $request, string $channel, string $token): JsonResponse
    {
        if (!$request->isSecure()) {
            return $this->failure('回执入口只接受 HTTPS。', 400);
        }
        if (!$request->isJson()) {
            return $this->failure('回执正文必须是 JSON。', 415);
        }
        $body = $request->getContent();
        if (strlen($body) > 262144) {
            return $this->failure('回执正文超过 256 KiB。', 413);
        }
        if ($body === '') {
            return $this->failure('回执正文不能为空。', 422);
        }

        $headers = $request->headers->all();
        // 清除外部同名请求头，令牌只能来自已匹配的 URL。
        unset($headers['x-ptadmin-notice-token']);
        $headers['x-ptadmin-notice-token'] = [$token];

        try {
            $adapter = $this->adapters->resolve($channel);
            if (!$adapter instanceof NoticeChannelReceiptAuthenticatorInterface) {
                return $this->failure('渠道未开放认证回执入口。', 404);
            }
            $this->deliveries->applyReceipt($channel, $body, $headers);
            return response()->json($adapter->receiptAcknowledgement())->header('Cache-Control', 'no-store');
        } catch (NoticeSendingException $exception) {
            Log::warning('通知回执请求被拒绝。', [
                'channel' => $channel,
                'error_code' => $exception->errorCode,
            ]);

            $status = match ($exception->errorCode) {
                'notice_receipt_unauthorized', 'notice_channel_disabled' => 403,
                'notice_channel_adapter_missing', 'notice_channel_receipt_unsupported' => 404,
                default => 422,
            };
            return $this->failure('回执校验失败。', $status);
        } catch (Throwable $exception) {
            Log::error('通知回执处理失败。', [
                'channel' => $channel,
                'exception_type' => $exception::class,
            ]);

            return $this->failure('回执处理失败。', 500);
        }
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return response()->json([
            'code' => 'FAIL',
            'message' => $message,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
