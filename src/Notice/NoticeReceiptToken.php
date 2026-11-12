<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

/** 不提供签名的 HTTP 回执使用专用 URL 凭据；不得复用供应商 API 密钥。 */
final class NoticeReceiptToken
{
    /** @param array<string, string|list<string>> $headers */
    public static function verify(string $expected, array $headers): void
    {
        $tokens = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-ptadmin-notice-token') {
                array_push($tokens, ...(is_array($value) ? $value : [$value]));
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expected) !== 1
            || count($tokens) !== 1 || !hash_equals($expected, $tokens[0])) {
            throw new NoticeSendingException('通知回执凭据无效。', 'notice_receipt_unauthorized');
        }
    }
}
