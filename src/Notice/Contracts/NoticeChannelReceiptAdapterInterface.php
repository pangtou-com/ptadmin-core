<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeReceipt;

/** 外部回执格式解析；公开入口还必须实现 NoticeChannelReceiptAuthenticatorInterface。 */
interface NoticeChannelReceiptAdapterInterface
{
    /** @param array<string, string|list<string>> $headers @return list<NoticeReceipt> */
    public function parseReceipt(string $body, array $headers = []): array;
}
