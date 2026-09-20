<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/** Queue 中只保存事件上下文和绑定描述符。 */
final class RuntimeEventJob implements ShouldQueue
{
    /** @param array<string, mixed> $context @param array<string, mixed> $binding @param list<string> $chain */
    public function __construct(
        public readonly array $context,
        public readonly array $binding,
        public readonly array $chain = [],
    ) {
    }

    public function handle(LoggerInterface $logger, RuntimeEventDispatcher $dispatcher): void
    {
        try {
            $dispatcher->executeQueued(RuntimeEventContext::fromArray($this->context), $this->binding, $this->chain);
        } catch (Throwable $exception) {
            $logger->warning('Runtime Event queued listener failed.', [
                'event_key' => $this->context['event_key'] ?? null,
                'resource_key' => $this->context['resource_key'] ?? null,
                'service' => $this->binding['service'] ?? null,
                'method' => $this->binding['method'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
