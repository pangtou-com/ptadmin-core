<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeRoutingContext;

interface NoticeChannelRoutingHookInterface
{
    /** @param list<string> $candidates */
    public function select(NoticeRoutingContext $context, array $candidates): ?string;
}
