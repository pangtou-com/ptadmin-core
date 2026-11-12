<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Contracts;

/** 可选的回执请求认证协议；实现应在解析正文前验证令牌、签名或供应商安全头。 */
interface NoticeChannelReceiptAuthenticatorInterface extends NoticeChannelReceiptAdapterInterface
{
    /** @param array<string, string|list<string>> $headers */
    public function verifyReceipt(string $body, array $headers = []): void;

    /** 供应商要求的成功应答；仅在投递事务提交后返回。 @return array<string, mixed> */
    public function receiptAcknowledgement(): array;
}
