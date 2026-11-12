<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Notice\Contracts\NoticeChannelQueryAdapterInterface;
use PTAdmin\Notice\Contracts\NoticeChannelReceiptAuthenticatorInterface;
use PTAdmin\Notice\Contracts\NoticeChannelReceiptAdapterInterface;
use Throwable;

final class NoticeDeliveryService
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly NoticeChannelAdapterRegistry $adapters,
    ) {
    }

    public function query(int $deliveryId): NoticeTargetResult
    {
        $delivery = $this->database->table('notice_deliveries')->where('id', $deliveryId)->first();
        if ($delivery === null) {
            throw new NoticeSendingException('通知投递记录不存在。', 'notice_delivery_not_found', ['delivery_id' => $deliveryId]);
        }
        $adapter = $this->adapters->resolve((string) $delivery->channel);
        if (!$adapter instanceof NoticeChannelQueryAdapterInterface) {
            throw new NoticeSendingException('发送渠道不支持状态查询。', 'notice_channel_query_unsupported', ['channel' => $delivery->channel]);
        }
        $response = $adapter->query(new NoticeDeliveryQuery(
            deliveryId: (int) $delivery->id,
            destination: (string) ($delivery->destination ?? ''),
            providerMessageId: $delivery->provider_message_id !== null ? (string) $delivery->provider_message_id : null,
            channel: (string) $delivery->channel,
            createdAt: (int) $delivery->created_at,
            meta: $this->decodeMeta($delivery->meta ?? null),
        ));
        $delivery = $this->database->transaction(function () use ($deliveryId, $response): object {
            $current = $this->database->table('notice_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            if ($current === null) {
                throw new NoticeSendingException('通知投递记录不存在。', 'notice_delivery_not_found');
            }
            $values = $this->responseValues($current, $response);
            if ($values !== []) {
                $this->database->table('notice_deliveries')->where('id', $deliveryId)->update($values);
                foreach ($values as $key => $value) {
                    $current->{$key} = $value;
                }
            }
            return $current;
        });

        return $this->result($delivery, $response);
    }

    /** @param array<string, string|list<string>> $headers */
    public function applyReceipt(string $channel, string $body, array $headers = []): int
    {
        $channel = strtolower(trim($channel));
        $adapter = $this->adapters->resolve($channel);
        if (!$adapter instanceof NoticeChannelReceiptAdapterInterface) {
            throw new NoticeSendingException('发送渠道不支持回执解析。', 'notice_channel_receipt_unsupported', ['channel' => $channel]);
        }
        if ($adapter instanceof NoticeChannelReceiptAuthenticatorInterface) {
            $adapter->verifyReceipt($body, $headers);
        }
        $receipts = $adapter->parseReceipt($body, $headers);
        if ($receipts === []) {
            return 0;
        }
        return $this->database->transaction(function () use ($channel, $receipts): int {
            $providerIds = [];
            foreach ($receipts as $receipt) {
                if ($receipt->providerMessageId !== null && $receipt->providerMessageId !== '') {
                    $providerIds[] = $receipt->providerMessageId;
                }
            }
            if ($providerIds === []) {
                return 0;
            }
            // 一次读取并锁定整个批次，避免重复回调与主动查询并发覆盖终态。
            $deliveries = $this->database->table('notice_deliveries')->where('channel', $channel)
                ->whereIn('provider_message_id', array_values(array_unique($providerIds)))
                ->orderBy('id')->lockForUpdate()->get();
            $byProviderId = [];
            foreach ($deliveries as $delivery) {
                $byProviderId[$delivery->provider_message_id][] = $delivery;
            }
            $changed = [];
            $matched = 0;
            foreach ($receipts as $receipt) {
                $candidates = $byProviderId[$receipt->providerMessageId ?? ''] ?? [];
                if ($receipt->destination !== null) {
                    $destinations = [$receipt->destination, ...$receipt->destinationAliases];
                    $candidates = array_values(array_filter($candidates,
                        static fn (object $delivery): bool => in_array($delivery->destination, $destinations, true)));
                }
                // 不使用手机号单独匹配，也不在重复业务编号中猜测目标。
                if (count($candidates) !== 1) {
                    continue;
                }
                $delivery = $candidates[0];
                $response = match ($receipt->status) {
                    'delivered' => NoticeChannelResponse::delivered($receipt->providerMessageId, $receipt->meta),
                    'failed' => NoticeChannelResponse::failed($receipt->errorCode ?? 'notice_receipt_failed', $receipt->errorMessage ?? '供应商回执报告发送失败。', $receipt->meta),
                    'accepted' => NoticeChannelResponse::accepted($receipt->providerMessageId, $receipt->meta),
                    default => NoticeChannelResponse::unknown($receipt->providerMessageId, $receipt->meta),
                };
                $values = $this->responseValues($delivery, $response, $receipt->deliveredAt);
                if ($values !== []) {
                    foreach ($values as $key => $value) {
                        $delivery->{$key} = $value;
                    }
                    $changed[$delivery->id] = (array) $delivery;
                }
                $matched++;
            }
            if ($changed !== []) {
                $this->database->table('notice_deliveries')->upsert(array_values($changed), ['id'], [
                    'status', 'provider_message_id', 'error_code', 'error_message',
                    'accepted_at', 'delivered_at', 'meta', 'updated_at',
                ]);
            }

            return $matched;
        });
    }

    /** @return array<string, mixed> */
    private function responseValues(object $delivery, NoticeChannelResponse $response, ?int $deliveredAt = null): array
    {
        $priority = ['unknown' => 0, 'pending' => 1, 'accepted' => 2, 'failed' => 3, 'delivered' => 4];
        if (($priority[$response->status] ?? 0) <= ($priority[$delivery->status] ?? 0)) {
            return [];
        }
        $status = $response->status;
        $now = time();
        $meta = $this->decodeMeta($delivery->meta ?? null);
        if ($response->meta !== []) {
            $meta['last_response'] = $response->meta;
        }

        return [
            'status' => $status,
            'provider_message_id' => $response->providerMessageId ?: $delivery->provider_message_id,
            'error_code' => $status === 'failed' ? ($response->errorCode ?: 'notice_channel_send_failed') : null,
            'error_message' => $status === 'failed' ? ($response->errorMessage ?: '发送渠道返回失败。') : null,
            'accepted_at' => in_array($status, ['accepted', 'delivered'], true) ? ($delivery->accepted_at ?: $now) : $delivery->accepted_at,
            'delivered_at' => $status === 'delivered' ? ($delivery->delivered_at ?: ($deliveredAt ?? $now)) : $delivery->delivered_at,
            'meta' => $meta !== [] ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'updated_at' => $now,
        ];
    }

    private function result(object $delivery, NoticeChannelResponse $response): NoticeTargetResult
    {
        $channel = $this->database->table('notice_channels')->where('code', $delivery->channel)->first();
        $errorCode = $delivery->status === 'failed' ? $delivery->error_code : null;
        $errorMessage = $delivery->status === 'failed' ? $delivery->error_message : null;

        return new NoticeTargetResult(
            channelType: (string) ($channel->type ?? 'external'),
            channel: (string) $delivery->channel,
            status: (string) $delivery->status,
            deliveryId: (int) $delivery->id,
            providerMessageId: $delivery->provider_message_id !== null ? (string) $delivery->provider_message_id : $response->providerMessageId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /** @return array<string, mixed> */
    private function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
