<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeReceipt
{
    /** @param array<string, mixed> $meta @param list<string> $destinationAliases 供应商确认等价的接收地址格式。 */
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly ?string $destination,
        public readonly string $status,
        public readonly ?int $deliveredAt = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
        public readonly array $destinationAliases = [],
    ) {
        if (!in_array($status, ['accepted', 'delivered', 'failed', 'unknown'], true)) {
            throw new NoticeSendingException('通知回执状态无效。', 'notice_receipt_status_invalid');
        }
    }
}
