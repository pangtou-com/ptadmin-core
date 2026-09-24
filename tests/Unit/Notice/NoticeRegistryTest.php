<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\NoticeRegistry;

final class NoticeRegistryTest extends TestCase
{
    public function testDefinitionsKeepTheirOwnerAndEditorShape(): void
    {
        $registry = new NoticeRegistry();
        $registry->beginOwner('addon', 'Demo');
        $registry->scene('auth.verify_code', '验证码通知')
            ->parameters([
                ['key' => 'code', 'label' => '验证码', 'type' => 'string'],
            ])
            ->channels(['sms', 'email'])
            ->supports(['action'])
            ->recordInApp();
        $registry->channel('sms', '阿里云短信')->editor('template');

        self::assertSame('addon', $registry->scenesFor('addon', 'demo')[0]['source_type']);
        self::assertSame(['sms', 'email'], $registry->scenesFor('addon', 'demo')[0]['channels']);
        self::assertTrue($registry->scenesFor('addon', 'demo')[0]['record_in_app']);
        self::assertSame('template', $registry->channelsFor('addon', 'demo')[0]['editor']);
        self::assertSame('demo.sms', $registry->channelsFor('addon', 'demo')[0]['code']);
    }

    public function testChannelCodeIsStableForSameTypeAndTitle(): void
    {
        $first = new NoticeRegistry();
        $first->channel('email', '邮件通知');
        $second = new NoticeRegistry();
        $second->channel('email', '邮件通知');

        self::assertSame(
            $first->channelsFor('system', 'ptadmin')[0]['code'],
            $second->channelsFor('system', 'ptadmin')[0]['code'],
        );
    }
}
