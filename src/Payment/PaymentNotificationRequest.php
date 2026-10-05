<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\ProtocolException;

/** 原始正文及头部只能交给验签适配器，不进入默认调试或日志输出。 */
final class PaymentNotificationRequest implements \JsonSerializable
{
    /** @param array<string,list<string>> $headers @param array<string,mixed> $query @param array<string,mixed> $form */
    public function __construct(
        private readonly string $body,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly array $form = [],
    ) {
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string,mixed> */
    public function form(): array
    {
        return $this->form;
    }

    /** @return array<never> */
    public function jsonSerialize(): array
    {
        return [];
    }

    /** @return array<never> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new ProtocolException('notification_serialization_forbidden', '原始支付通知不能进入普通序列化。');
    }
}
