<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use JsonException;

final class RuntimeRegistrySnapshot
{
    /** @param array<string, mixed> $descriptor */
    public function __construct(private array $descriptor)
    {
        $this->descriptor = self::sortKeys($descriptor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->descriptor;
    }

    public function checksum(): string
    {
        $payload = $this->descriptor;
        unset($payload['checksum']);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new RuntimeDiagnostic('runtime_descriptor_invalid', 'Runtime descriptor cannot be encoded.', ['error' => $exception->getMessage()]);
        }
    }

    public function isEmpty(): bool
    {
        return ($this->descriptor['resources'] ?? []) === [];
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = self::sortKeys($item);
            } elseif (is_array($item)) {
                $value[$key] = array_map(static fn ($entry) => is_array($entry) && !array_is_list($entry) ? self::sortKeys($entry) : $entry, $item);
            }
        }
        ksort($value, SORT_STRING);

        return $value;
    }
}
