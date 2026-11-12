<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\NoticeReceiptToken;
use PTAdmin\Notice\NoticeSendingException;

final class NoticeReceiptTokenTest extends TestCase
{
    public function testOnlyOneExactTokenIsAccepted(): void
    {
        $token = str_repeat('a', 48);
        NoticeReceiptToken::verify($token, ['X-PTAdmin-Notice-Token' => $token]);
        foreach ([
            [],
            ['x-ptadmin-notice-token' => str_repeat('b', 48)],
            ['x-ptadmin-notice-token' => [$token, $token]],
            ['x-ptadmin-notice-token' => $token, 'X-PTAdmin-Notice-Token' => $token],
            ['x-ptadmin-notice-token' => ' '.$token],
        ] as $headers) {
            try {
                NoticeReceiptToken::verify($token, $headers);
                self::fail('缺失、重复或不完全匹配的令牌不得通过');
            } catch (NoticeSendingException $exception) {
                self::assertSame('notice_receipt_unauthorized', $exception->errorCode);
            }
        }
    }

    public function testEmptyOrWeakConfiguredTokenIsRejectedEvenWhenItMatches(): void
    {
        foreach (['', 'short', str_repeat('a', 129), str_repeat('/', 48)] as $token) {
            try {
                NoticeReceiptToken::verify($token, ['x-ptadmin-notice-token' => $token]);
                self::fail('未配置或不符合长度和字符约束的令牌不得通过');
            } catch (NoticeSendingException $exception) {
                self::assertSame('notice_receipt_unauthorized', $exception->errorCode);
            }
        }
    }
}
