<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Config\RequiredStarttlsTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class RequiredStarttlsTransportTest extends TestCase
{
    public function testPreservesConfiguredConnectionAndCredentials(): void
    {
        $configured = new EsmtpTransport('smtp.example.test', 587, false);
        $configured->setUsername('mailer');
        $configured->setPassword('test-password');

        $transport = new RequiredStarttlsTransport($configured);

        self::assertSame($configured->getStream(), $transport->getStream());
        self::assertSame('mailer', $transport->getUsername());
        self::assertSame('test-password', $transport->getPassword());
        self::assertSame((string) $configured, (string) $transport);
    }

    /** @dataProvider sensitiveCommands */
    public function testRejectsSensitiveCommandsBeforeTls(string $command): void
    {
        $transport = new RequiredStarttlsTransport(new EsmtpTransport('smtp.example.test', 587, false));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('SMTP server does not support required STARTTLS.');
        $transport->executeCommand($command, [250]);
    }

    public static function sensitiveCommands(): array
    {
        return [
            ["AUTH LOGIN\r\n"],
            ["MAIL FROM:<sender@example.test>\r\n"],
            ["RCPT TO:<receiver@example.test>\r\n"],
            ["DATA\r\n"],
        ];
    }
}
