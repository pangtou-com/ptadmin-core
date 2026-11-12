<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeChannelBatchResponse
{
    /** @param list<NoticeChannelResponse> $responses @param array<string, mixed> $meta */
    private function __construct(
        public readonly array $responses,
        public readonly ?string $providerBatchId,
        public readonly array $meta,
    ) {
    }

    /** @param list<NoticeChannelResponse> $responses @param array<string, mixed> $meta */
    public static function completed(array $responses, ?string $providerBatchId = null, array $meta = []): self
    {
        return new self(array_values($responses), $providerBatchId, $meta);
    }
}
