<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use JsonException;
use RuntimeException;

/** Resource Event 的可序列化上下文，不携带 Request、模型、连接或容器对象。 */
final class RuntimeEventContext
{
    /** @param array<string, scalar|null> $actor @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventKey,
        public readonly string $resourceKey,
        public readonly ?int $recordId,
        public readonly array $actor,
        public readonly string $occurredAt,
        public readonly string $requestId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {
        if ($eventId === '' || $eventKey === '' || $resourceKey === '' || $idempotencyKey === '') {
            throw new RuntimeException('Runtime Event context contains an empty stable identifier.');
        }
        $this->assertPayloadValues($payload);
        try {
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Runtime Event payload must be JSON serializable.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadValues(array $payload): void
    {
        foreach ($payload as $value) {
            if (is_array($value)) {
                $this->assertPayloadValues($value);
                continue;
            }
            if ($value instanceof \stdClass) {
                $this->assertPayloadValues(get_object_vars($value));
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                throw new RuntimeException('Runtime Event payload cannot contain a model, Request, Closure or resource.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_key' => $this->eventKey,
            'resource_key' => $this->resourceKey,
            'record_id' => $this->recordId,
            'actor' => $this->actor,
            'occurred_at' => $this->occurredAt,
            'request_id' => $this->requestId,
            'payload' => $this->payload,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (string) ($value['event_id'] ?? ''),
            (string) ($value['event_key'] ?? ''),
            (string) ($value['resource_key'] ?? ''),
            isset($value['record_id']) ? (int) $value['record_id'] : null,
            (array) ($value['actor'] ?? []),
            (string) ($value['occurred_at'] ?? ''),
            (string) ($value['request_id'] ?? ''),
            (array) ($value['payload'] ?? []),
            (string) ($value['idempotency_key'] ?? ''),
        );
    }
}
