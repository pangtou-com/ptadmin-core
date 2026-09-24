<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeChannelMessage;
use PTAdmin\Notice\NoticeChannelResponse;

/** 外部发送渠道适配器；站内信由 Core 直接处理，不经过该接口。 */
interface NoticeChannelAdapterInterface
{
    public function send(NoticeChannelMessage $message): NoticeChannelResponse;
}
