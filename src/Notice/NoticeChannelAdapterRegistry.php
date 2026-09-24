<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Notice\Contracts\NoticeChannelAdapterInterface;

final class NoticeChannelAdapterRegistry
{
    /** @var array<string, NoticeChannelAdapterInterface|class-string<NoticeChannelAdapterInterface>> */
    private array $adapters = [];

    public function __construct(private Container $container)
    {
    }

    /** @param NoticeChannelAdapterInterface|string $adapter */
    public function register(string $channel, NoticeChannelAdapterInterface|string $adapter): void
    {
        $channel = strtolower(trim($channel));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,149}$/', $channel) !== 1) {
            throw new NoticeSendingException('发送渠道编码无效。', 'notice_channel_invalid', ['channel' => $channel]);
        }
        if (is_string($adapter) && !is_a($adapter, NoticeChannelAdapterInterface::class, true)) {
            throw new NoticeSendingException('发送渠道适配器必须实现规定接口。', 'notice_channel_adapter_invalid', ['channel' => $channel]);
        }
        $this->adapters[$channel] = $adapter;
    }

    public function resolve(string $channel): NoticeChannelAdapterInterface
    {
        $channel = strtolower(trim($channel));
        $adapter = $this->adapters[$channel] ?? null;
        if ($adapter === null) {
            throw new NoticeSendingException('发送渠道没有注册运行时适配器。', 'notice_channel_adapter_missing', ['channel' => $channel]);
        }
        if (is_string($adapter)) {
            $resolved = $this->container->make($adapter);
            if (!$resolved instanceof NoticeChannelAdapterInterface) {
                throw new NoticeSendingException('发送渠道适配器解析结果无效。', 'notice_channel_adapter_invalid', ['channel' => $channel]);
            }
            $this->adapters[$channel] = $resolved;

            return $resolved;
        }

        return $adapter;
    }
}
