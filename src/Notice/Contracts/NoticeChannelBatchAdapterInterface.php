<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeChannelBatchResponse;
use PTAdmin\Notice\NoticeChannelMessage;

/** 可选的批量外部通知适配器；Core 会按消息顺序应用逐条结果。 */
interface NoticeChannelBatchAdapterInterface
{
    /** @param list<NoticeChannelMessage> $messages */
    public function sendBatch(array $messages): NoticeChannelBatchResponse;
}
