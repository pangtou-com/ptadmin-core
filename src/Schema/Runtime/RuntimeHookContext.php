<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Closure;
use PTAdmin\Schema\Definition\Schema;

/**
 * 请求内 Hook/Action 调用上下文。上下文只在执行阶段存在，不会写入 Runtime 缓存。
 */
final class RuntimeHookContext
{
    /** @param array<string, mixed> $input @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $resourceKey,
        public readonly string $stage,
        public readonly ?Schema $schema = null,
        public readonly ?int $recordId = null,
        public readonly array $input = [],
        public readonly ?string $actionKey = null,
        public readonly array $metadata = [],
        private readonly ?Closure $eventRecorder = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function recordEvent(string $eventKey, array $payload = []): void
    {
        if ($this->eventRecorder === null) {
            throw new RuntimeDiagnostic('event_context_unavailable', 'Runtime Event recorder is unavailable for this context.');
        }

        ($this->eventRecorder)($eventKey, $payload);
    }
}
