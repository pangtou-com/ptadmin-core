<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeRegistry;

/** 由 Core 或插件提供通知场景和发送渠道的代码声明。 */
interface NoticeProviderInterface
{
    public function register(NoticeRegistry $registry): void;
}
