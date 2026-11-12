<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

use PTAdmin\Notice\NoticeReceipt;

/** 可选的外部回执解析适配器；实现必须在解析阶段完成供应商签名校验。 */
interface NoticeChannelReceiptAdapterInterface
{
    /** @param array<string, string|list<string>> $headers @return list<NoticeReceipt> */
    public function parseReceipt(string $body, array $headers = []): array;
}
