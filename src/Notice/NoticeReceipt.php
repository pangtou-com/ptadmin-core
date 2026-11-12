<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeReceipt
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly ?string $destination,
        public readonly string $status,
        public readonly ?int $deliveredAt = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
    ) {
        if (!in_array($status, ['accepted', 'delivered', 'failed', 'unknown'], true)) {
            throw new NoticeSendingException('通知回执状态无效。', 'notice_receipt_status_invalid');
        }
    }
}
