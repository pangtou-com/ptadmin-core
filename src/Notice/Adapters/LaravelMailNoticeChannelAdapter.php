<?php

declare(strict_types=1);

namespace PTAdmin\Notice\Adapters;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Mail\SentMessage;
use PTAdmin\Notice\Contracts\NoticeChannelAdapterInterface;
use PTAdmin\Notice\Mail\InternalNoticeMail;
use PTAdmin\Notice\NoticeChannelMessage;
use PTAdmin\Notice\NoticeChannelResponse;
use PTAdmin\Notice\NoticeSendingException;

final class LaravelMailNoticeChannelAdapter implements NoticeChannelAdapterInterface
{
    public function __construct(private Mailer $mailer, private UrlGenerator $urls)
    {
    }

    public function send(NoticeChannelMessage $message): NoticeChannelResponse
    {
        if ($message->template->editor !== 'html') {
            throw new NoticeSendingException('系统邮件渠道必须使用 html 编辑器。', 'notice_email_template_invalid');
        }
        if (filter_var($message->destination, FILTER_VALIDATE_EMAIL) === false) {
            throw new NoticeSendingException('邮件接收地址无效。', 'notice_email_destination_invalid');
        }

        $subject = $message->template->config['subject'] ?? null;
        $html = $message->template->config['html'] ?? null;
        $text = $message->template->config['text'] ?? '';
        if (!is_string($subject) || !is_string($html) || !is_string($text)) {
            throw new NoticeSendingException('系统邮件模板内容无效。', 'notice_email_template_invalid');
        }

        [$html, $text] = $this->appendAction($html, $text, $message->template->action);
        $sent = $this->mailer->to($message->destination)->send(new InternalNoticeMail($subject, $html, $text));
        $messageId = $sent instanceof SentMessage ? $sent->getMessageId() : null;

        return NoticeChannelResponse::accepted($messageId, ['transport' => 'laravel_mail']);
    }

    /**
     * @param array{type:string,target:string,label?:string}|null $action
     * @return array{string,string}
     */
    private function appendAction(string $html, string $text, ?array $action): array
    {
        if ($action === null) {
            return [$html, $text];
        }
        $target = $this->absoluteTarget($action['target']);
        $label = $action['label'] ?? '查看详情';
        $plain = $text !== '' ? $text : trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $safeTarget = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= '<p><a href="'.$safeTarget.'">'.$safeLabel.'</a></p>';
        $text = rtrim($plain)."\n\n".$label.': '.$target;

        return [$html, $text];
    }

    private function absoluteTarget(string $target): string
    {
        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }
        if (str_starts_with($target, '/')) {
            return $this->urls->to($target);
        }

        throw new NoticeSendingException('邮件跳转地址必须是站内路径或 HTTP(S) 地址。', 'notice_email_action_invalid');
    }
}
