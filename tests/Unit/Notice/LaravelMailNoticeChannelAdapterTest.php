<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\Adapters\LaravelMailNoticeChannelAdapter;
use PTAdmin\Notice\Mail\InternalNoticeMail;
use PTAdmin\Notice\NoticeChannelMessage;
use PTAdmin\Notice\NoticeReceiver;
use PTAdmin\Notice\NoticeSendingException;
use PTAdmin\Notice\ResolvedNoticeTemplate;

final class LaravelMailNoticeChannelAdapterTest extends TestCase
{
    public function testItSendsHtmlMailAndCarriesTheAction(): void
    {
        $mailer = new CapturingMailer();
        $adapter = new LaravelMailNoticeChannelAdapter(
            $mailer,
            new UrlGenerator(new RouteCollection(), Request::create('https://admin.example.com')),
        );
        $template = new ResolvedNoticeTemplate(
            scene: 'security.warning',
            channel: 'ptadmin.email',
            channelType: 'email',
            locale: 'zh-CN',
            editor: 'html',
            config: ['subject' => '安全提醒', 'html' => '<p>发现异常登录</p>', 'text' => '发现异常登录'],
            parameters: [],
            action: ['type' => 'url', 'target' => '/admin/security/1', 'label' => '查看记录'],
        );

        $response = $adapter->send(new NoticeChannelMessage(1, 'admin@example.com', $template, NoticeReceiver::admin(7)));

        self::assertSame('accepted', $response->status);
        self::assertSame('admin@example.com', $mailer->recipient);
        self::assertInstanceOf(InternalNoticeMail::class, $mailer->mail);
        self::assertSame('安全提醒', $mailer->mail->noticeSubject);
        self::assertStringContainsString('href="https://admin.example.com/admin/security/1"', $mailer->mail->htmlBody);
        self::assertStringContainsString('查看记录: https://admin.example.com/admin/security/1', $mailer->mail->textBody);
    }

    public function testItRejectsInvalidEmailDestinations(): void
    {
        $adapter = new LaravelMailNoticeChannelAdapter(
            new CapturingMailer(),
            new UrlGenerator(new RouteCollection(), Request::create('https://admin.example.com')),
        );
        $template = new ResolvedNoticeTemplate(
            'security.warning',
            'ptadmin.email',
            'email',
            'zh-CN',
            'html',
            ['subject' => '提醒', 'html' => '<p>正文</p>', 'text' => '正文'],
            [],
        );

        $this->expectException(NoticeSendingException::class);
        $this->expectExceptionMessage('邮件接收地址无效。');
        $adapter->send(new NoticeChannelMessage(1, 'invalid-address', $template));
    }
}

final class CapturingMailer implements Mailer
{
    public mixed $recipient = null;

    public ?InternalNoticeMail $mail = null;

    public function to($users)
    {
        $this->recipient = $users;

        return $this;
    }

    public function bcc($users)
    {
        return $this;
    }

    public function raw($text, $callback)
    {
        return null;
    }

    public function send($view, array $data = [], $callback = null)
    {
        if ($view instanceof InternalNoticeMail) {
            $this->mail = $view;
        }

        return null;
    }
}
