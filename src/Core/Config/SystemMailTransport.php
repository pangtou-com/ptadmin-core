<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** 每次投递读取系统邮件配置，保存设置后无需重启队列或清除 Laravel mailer 缓存。 */
final class SystemMailTransport implements TransportInterface
{
    public function __construct(private ConfigService $configs, private MailManager $manager)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $values = $this->configs->get('core', 'ptadmin', 'mail')['effective_values'];
        if ($message instanceof Email) {
            $message->from(new Address($values['from_address'], $values['from_name']));
            $envelope = new Envelope($message->getFrom()[0], $envelope?->getRecipients() ?? Envelope::create($message)->getRecipients());
        }

        return $this->transport($values)->send($message, $envelope);
    }

    /** @param array<string, mixed> $values */
    public function transport(array $values): TransportInterface
    {
        $transport = $this->manager->createSymfonyTransport([
            'transport' => 'smtp',
            'scheme' => $values['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $values['host'],
            'port' => $values['port'],
            'username' => $values['username'] ?: null,
            'password' => $values['password'] ?: null,
            'timeout' => 10,
        ]);

        if ($values['encryption'] === 'tls') {
            if (!$transport instanceof EsmtpTransport) {
                throw new \RuntimeException('STARTTLS requires an SMTP transport.');
            }
            return new RequiredStarttlsTransport($transport);
        }

        return $transport;
    }

    public function __toString(): string
    {
        return 'ptadmin://mail';
    }
}
