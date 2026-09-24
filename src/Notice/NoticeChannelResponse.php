<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeChannelResponse
{
    /** @param array<string, mixed> $meta */
    private function __construct(
        public readonly string $status,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $meta,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function accepted(?string $providerMessageId = null, array $meta = []): self
    {
        return new self('accepted', $providerMessageId, null, null, $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function delivered(?string $providerMessageId = null, array $meta = []): self
    {
        return new self('delivered', $providerMessageId, null, null, $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function failed(string $errorCode, string $errorMessage, array $meta = []): self
    {
        return new self('failed', null, trim($errorCode), trim($errorMessage), $meta);
    }
}
