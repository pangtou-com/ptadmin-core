<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeRoutingContext
{
    /**
     * @param array<string, string|int|float|bool> $parameters
     * @param array{type:string,target:string,label?:string}|null $action
     */
    public function __construct(
        public readonly string $scene,
        public readonly string $channelType,
        public readonly string $locale,
        public readonly ?string $destination,
        public readonly array $parameters,
        public readonly ?array $action,
        public readonly ?NoticeReceiver $receiver,
    ) {
    }
}
