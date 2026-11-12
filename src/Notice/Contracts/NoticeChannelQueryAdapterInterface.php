<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeChannelResponse;
use PTAdmin\Notice\NoticeDeliveryQuery;

/** 可选的外部通知状态查询适配器。 */
interface NoticeChannelQueryAdapterInterface
{
    public function query(NoticeDeliveryQuery $query): NoticeChannelResponse;
}
