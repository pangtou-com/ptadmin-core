<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class ResolvedNoticeTemplate
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, string|int|float|bool> $parameters
     * @param array{type:string,target:string,label?:string}|null $action
     */
    public function __construct(
        public readonly string $scene,
        public readonly string $channel,
        public readonly string $channelType,
        public readonly string $locale,
        public readonly string $editor,
        public readonly array $config,
        public readonly array $parameters,
        public readonly ?array $action = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scene' => $this->scene,
            'channel' => $this->channel,
            'channel_type' => $this->channelType,
            'locale' => $this->locale,
            'editor' => $this->editor,
            'config' => $this->config,
            'parameters' => $this->parameters,
            'action' => $this->action,
        ];
    }
}
