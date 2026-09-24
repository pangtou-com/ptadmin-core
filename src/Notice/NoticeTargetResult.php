<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeTargetResult
{
    public function __construct(
        public readonly string $channelType,
        public readonly string $channel,
        public readonly string $status,
        public readonly ?int $noticeMessageId = null,
        public readonly ?int $deliveryId = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function successful(): bool
    {
        return in_array($this->status, ['accepted', 'delivered'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channel_type' => $this->channelType,
            'channel' => $this->channel,
            'status' => $this->status,
            'notice_message_id' => $this->noticeMessageId,
            'delivery_id' => $this->deliveryId,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
