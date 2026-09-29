<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;

final class MailConfigurationTester
{
    public function __construct(private ConfigService $configs, private ConfigValueCodec $codec, private SystemMailTransport $mailTransport)
    {
    }

    /** @param array<string, mixed> $input */
    public function send(string $recipient, array $input): void
    {
        $configuration = $this->configs->get('core', 'ptadmin', 'mail');
        $input['password'] = ($input['password'] ?? '') === ''
            ? $configuration['effective_values']['password']
            : $input['password'];
        $values = $this->codec->encode($configuration['definition']['schema'], $input);
        $transport = $this->mailTransport->transport($values);
        $mailer = new Mailer('ptadmin-mail-test', app('view'), $transport, app('events'));

        $sent = $mailer->raw('这是一封 PTAdmin 邮件配置测试邮件。', static function (Message $message) use ($recipient, $values): void {
            $message->from($values['from_address'], $values['from_name'])
                ->to($recipient)
                ->subject('PTAdmin 邮件发送测试');
        });

        if ($sent === null) {
            throw new \RuntimeException('Test mail was not accepted by the mail transport.');
        }
    }
}
