<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** 拒绝在未完成 STARTTLS 时发送认证信息或邮件内容。 */
final class RequiredStarttlsTransport extends EsmtpTransport
{
    public function __construct(EsmtpTransport $configured)
    {
        $stream = $configured->getStream();
        parent::__construct(
            $stream->getHost(),
            $stream->getPort(),
            false,
            null,
            null,
            $stream,
        );
        $this->setUsername($configured->getUsername());
        $this->setPassword($configured->getPassword());
    }

    public function executeCommand(string $command, array $codes): string
    {
        if (!$this->getStream()->isTLS() && preg_match('/^(?:AUTH|MAIL FROM|RCPT TO|DATA)\b/i', $command)) {
            throw new TransportException('SMTP server does not support required STARTTLS.');
        }

        return parent::executeCommand($command, $codes);
    }
}
