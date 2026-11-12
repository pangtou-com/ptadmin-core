<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeDeliveryQuery
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $destination,
        public readonly ?string $providerMessageId,
        public readonly string $channel,
        public readonly int $createdAt,
        public readonly array $meta,
    ) {
    }
}
