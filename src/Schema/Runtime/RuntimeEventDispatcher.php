<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/** 在主事务提交后分发 Resource Event，隔离 Listener 失败。 */
final class RuntimeEventDispatcher
{
    private const MAX_DEPTH = 8;

    /** @var list<string> */
    private array $activeChain = [];

    /** @var array<string, true> */
    private array $dispatchedKeys = [];

    public function __construct(
        private Container $container,
        private Queue $queue,
        private LoggerInterface $logger,
    ) {
    }

    public function schedule(RuntimeRegistrySnapshot $snapshot, RuntimeEventContext $context): void
    {
        $bindings = $this->bindings($snapshot, $context);
        if ($bindings === []) {
            return;
        }

        DB::afterCommit(function () use ($bindings, $context): void {
            $this->dispatchCommitted($context, $bindings);
        });
    }

    /** @param list<array<string, mixed>> $bindings */
    private function dispatchCommitted(RuntimeEventContext $context, array $bindings): void
    {
        if (in_array($context->eventKey, $this->activeChain, true)) {
            $this->diagnostic('event_recursion_blocked', $context, ['reason' => 'event_key_cycle']);
            return;
        }
        if (isset($this->dispatchedKeys[$context->idempotencyKey])) {
            $this->diagnostic('event_recursion_blocked', $context, ['reason' => 'duplicate_idempotency_key']);
            return;
        }
        if (count($this->activeChain) >= self::MAX_DEPTH) {
            $this->diagnostic('event_recursion_blocked', $context, ['reason' => 'maximum_depth']);
            return;
        }

        $this->dispatchedKeys[$context->idempotencyKey] = true;
        $this->activeChain[] = $context->eventKey;
        try {
            foreach ($bindings as $binding) {
                if (($binding['mode'] ?? EventMode::SyncAfterCommit->value) === EventMode::Queued->value) {
                    $this->queue($context, $binding);
                    continue;
                }
                $this->invoke($context, $binding);
            }
        } finally {
            array_pop($this->activeChain);
        }
    }

    /** @param array<string, mixed> $binding @param list<string> $chain */
    public function executeQueued(RuntimeEventContext $context, array $binding, array $chain = []): void
    {
        $previous = $this->activeChain;
        $this->activeChain = $chain;
        try {
            if (in_array($context->eventKey, $this->activeChain, true)) {
                $this->diagnostic('event_recursion_blocked', $context, ['reason' => 'event_key_cycle']);
                return;
            }
            $this->activeChain[] = $context->eventKey;
            $this->invoke($context, $binding);
        } finally {
            $this->activeChain = $previous;
        }
    }

    /** @param array<string, mixed> $binding */
    private function queue(RuntimeEventContext $context, array $binding): void
    {
        try {
            $this->queue->push(new RuntimeEventJob($context->toArray(), $binding, array_slice($this->activeChain, 0, -1)));
        } catch (Throwable $exception) {
            $this->diagnostic('event_dispatch_failed', $context, [
                'mode' => EventMode::Queued->value,
                'service' => $binding['service'] ?? null,
                'method' => $binding['method'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $binding */
    private function invoke(RuntimeEventContext $context, array $binding): void
    {
        try {
            $service = (string) ($binding['service'] ?? '');
            $method = (string) ($binding['method'] ?? '');
            $instance = $this->container->make($service);
            $instance->{$method}($context);
        } catch (Throwable $exception) {
            $this->diagnostic('event_listener_failed', $context, [
                'service' => $binding['service'] ?? null,
                'method' => $binding['method'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function bindings(RuntimeRegistrySnapshot $snapshot, RuntimeEventContext $context): array
    {
        $resource = $snapshot->toArray()['resources'][$context->resourceKey] ?? null;
        if (!is_array($resource)) {
            return [];
        }
        $bindings = $resource['events'][$context->eventKey] ?? [];

        return is_array($bindings) ? array_values(array_filter($bindings, 'is_array')) : [];
    }

    /** @param array<string, scalar|null> $details */
    private function diagnostic(string $code, RuntimeEventContext $context, array $details): void
    {
        $this->logger->warning('Runtime Event dispatch diagnostic.', [
            'code' => $code,
            'event_id' => $context->eventId,
            'event_key' => $context->eventKey,
            'resource_key' => $context->resourceKey,
            'idempotency_key' => $context->idempotencyKey,
            ...$details,
        ]);
    }
}
