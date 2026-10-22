<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/** 拒绝在未完成 STARTTLS 时发送认证信息或邮件内容。 */
final class RequiredStarttlsTransport extends EsmtpTransport
{
    private readonly SocketStream $socketStream;

    public function __construct(EsmtpTransport $configured)
    {
        $stream = $configured->getStream();
        if (!$stream instanceof SocketStream) {
            throw new TransportException('Required STARTTLS transport requires an SMTP socket stream.');
        }
        $this->socketStream = $stream;
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
        if (!$this->socketStream->isTLS() && preg_match('/^(?:AUTH|MAIL FROM|RCPT TO|DATA)\b/i', $command)) {
            throw new TransportException('SMTP server does not support required STARTTLS.');
        }

        return parent::executeCommand($command, $codes);
    }
}
