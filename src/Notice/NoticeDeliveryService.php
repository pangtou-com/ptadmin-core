<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Notice\Contracts\NoticeChannelQueryAdapterInterface;
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
        $this->applyResponse($delivery, $response);

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
        $receipts = $adapter->parseReceipt($body, $headers);
        if ($receipts === []) {
            return 0;
        }
        $providerIds = [];
        $destinations = [];
        foreach ($receipts as $receipt) {
            if ($receipt->providerMessageId !== null && $receipt->providerMessageId !== '') {
                $providerIds[] = $receipt->providerMessageId;
            }
            if ($receipt->destination !== null && $receipt->destination !== '') {
                $destinations[] = $receipt->destination;
            }
        }
        if ($providerIds === [] && $destinations === []) {
            return 0;
        }
        $query = $this->database->table('notice_deliveries')->where('channel', $channel);
        $query->where(static function ($builder) use ($providerIds, $destinations): void {
            if ($providerIds !== []) {
                $builder->whereIn('provider_message_id', array_values(array_unique($providerIds)));
            }
            if ($destinations !== []) {
                $method = $providerIds !== [] ? 'orWhereIn' : 'whereIn';
                $builder->{$method}('destination', array_values(array_unique($destinations)));
            }
        });
        $deliveries = $query->get();
        $byProviderId = [];
        $byDestination = [];
        $byProviderDestination = [];
        foreach ($deliveries as $delivery) {
            if ($delivery->provider_message_id !== null && $delivery->provider_message_id !== '') {
                $byProviderId[(string) $delivery->provider_message_id][] = $delivery;
            }
            if ($delivery->destination !== null && $delivery->destination !== '') {
                $byDestination[(string) $delivery->destination][] = $delivery;
            }
            if ($delivery->provider_message_id !== null && $delivery->provider_message_id !== ''
                && $delivery->destination !== null && $delivery->destination !== '') {
                $byProviderDestination[(string) $delivery->provider_message_id.'|'.(string) $delivery->destination] = $delivery;
            }
        }
        $matched = 0;
        foreach ($receipts as $receipt) {
            $hasProviderId = $receipt->providerMessageId !== null && $receipt->providerMessageId !== '';
            $hasDestination = $receipt->destination !== null && $receipt->destination !== '';
            $delivery = null;
            if ($hasProviderId && $hasDestination) {
                $delivery = $byProviderDestination[$receipt->providerMessageId.'|'.$receipt->destination] ?? null;
            } elseif ($hasProviderId && count($byProviderId[$receipt->providerMessageId] ?? []) === 1) {
                $delivery = $byProviderId[$receipt->providerMessageId][0];
            } elseif ($hasDestination && count($byDestination[$receipt->destination] ?? []) === 1) {
                $delivery = $byDestination[$receipt->destination][0];
            }
            if ($delivery === null) {
                continue;
            }
            $response = match ($receipt->status) {
                'delivered' => NoticeChannelResponse::delivered($receipt->providerMessageId, $receipt->meta),
                'failed' => NoticeChannelResponse::failed($receipt->errorCode ?? 'notice_receipt_failed', $receipt->errorMessage ?? '供应商回执报告发送失败。', $receipt->meta),
                'accepted' => NoticeChannelResponse::accepted($receipt->providerMessageId, $receipt->meta),
                default => NoticeChannelResponse::unknown($receipt->providerMessageId, $receipt->meta),
            };
            $this->applyResponse($delivery, $response, $receipt->deliveredAt);
            $matched++;
        }

        return $matched;
    }

    private function applyResponse(object $delivery, NoticeChannelResponse $response, ?int $deliveredAt = null): void
    {
        $current = (string) ($delivery->status ?? 'pending');
        $status = $this->mergeStatus($current, $response->status);
        $now = time();
        $meta = $this->decodeMeta($delivery->meta ?? null);
        if ($response->meta !== []) {
            $meta['last_response'] = $response->meta;
        }
        $values = [
            'status' => $status,
            'provider_message_id' => $response->providerMessageId ?: ($delivery->provider_message_id ?? null),
            'error_code' => $status === 'failed' ? ($response->errorCode ?: 'notice_channel_send_failed') : null,
            'error_message' => $status === 'failed' ? ($response->errorMessage ?: '发送渠道返回失败。') : null,
            'accepted_at' => in_array($status, ['accepted', 'delivered'], true) ? ($delivery->accepted_at ?: $now) : $delivery->accepted_at,
            'delivered_at' => $status === 'delivered' ? ($deliveredAt ?: ($delivery->delivered_at ?: $now)) : $delivery->delivered_at,
            'meta' => $meta !== [] ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'updated_at' => $now,
        ];
        $this->database->table('notice_deliveries')->where('id', $delivery->id)->update($values);
        foreach ($values as $key => $value) {
            $delivery->{$key} = $value;
        }
    }

    private function mergeStatus(string $current, string $incoming): string
    {
        $priority = ['unknown' => 0, 'pending' => 1, 'accepted' => 2, 'failed' => 3, 'delivered' => 4];

        return ($priority[$incoming] ?? 0) >= ($priority[$current] ?? 0) ? $incoming : $current;
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
