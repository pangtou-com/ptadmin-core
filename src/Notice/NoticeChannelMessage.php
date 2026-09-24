<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeChannelMessage
{
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $destination,
        public readonly ResolvedNoticeTemplate $template,
        public readonly ?NoticeReceiver $receiver = null,
    ) {
    }
}
