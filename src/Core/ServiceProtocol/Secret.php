<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

/** 仅供服务端适配器读取；防止令牌被默认 JSON、调试或队列序列化泄露。 */
final class Secret implements \JsonSerializable
{
    public function __construct(private readonly string $value)
    {
        Input::nonEmpty($value, 'secret');
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new ProtocolException('secret_serialization_forbidden', '服务端凭据不能进入普通序列化。');
    }
}
