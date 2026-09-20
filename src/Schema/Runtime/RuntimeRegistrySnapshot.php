<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use JsonException;

final class RuntimeRegistrySnapshot
{
    /** @param array<string, mixed> $descriptor */
    public function __construct(private array $descriptor)
    {
        $this->assertSerializable($descriptor);
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

    /**
     * 返回按 Owner 替换后的临时快照，用于插件生命周期校验。
     * RuntimeRegistry 本身保持不可变，避免为验证重放宿主注册闭包。
     *
     * @param array{type:string,code:string} $owner
     */
    public function replaceOwner(array $owner, ?self $replacement): self
    {
        $resources = [];
        foreach ((array) ($this->descriptor['resources'] ?? []) as $resourceKey => $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $filtered = $this->filterResourceOwner($resource, $owner);
            if ($filtered !== null) {
                $resources[(string) $resourceKey] = $filtered;
            }
        }

        if ($replacement !== null) {
            foreach ((array) ($replacement->toArray()['resources'] ?? []) as $resourceKey => $resource) {
                if (!is_array($resource)) {
                    continue;
                }
                if (!isset($resources[$resourceKey])) {
                    $resources[(string) $resourceKey] = $resource;
                    continue;
                }
                $resources[(string) $resourceKey] = $this->mergeResource((string) $resourceKey, $resources[(string) $resourceKey], $resource);
            }
        }

        $sources = (array) ($this->descriptor['sources'] ?? []);
        unset($sources[$owner['type'].'/'.$owner['code']]);
        if ($replacement !== null) {
            foreach ((array) ($replacement->toArray()['sources'] ?? []) as $source => $checksum) {
                $sources[(string) $source] = $checksum;
            }
        }

        return new self([
            'format' => $this->descriptor['format'] ?? RuntimeRegistry::FORMAT,
            'sources' => $sources,
            'resources' => $resources,
        ]);
    }

    /** @param array<string,mixed> $resource @param array{type:string,code:string} $owner @return array<string,mixed>|null */
    private function filterResourceOwner(array $resource, array $owner): ?array
    {
        foreach (['hooks', 'events'] as $group) {
            foreach ((array) ($resource[$group] ?? []) as $key => $bindings) {
                $resource[$group][$key] = array_values(array_filter(
                    is_array($bindings) ? $bindings : [],
                    fn (mixed $binding): bool => !$this->belongsToOwner($binding, $owner),
                ));
                if ($resource[$group][$key] === []) {
                    unset($resource[$group][$key]);
                }
            }
        }
        foreach ((array) ($resource['actions'] ?? []) as $key => $binding) {
            if ($this->belongsToOwner($binding, $owner)) {
                unset($resource['actions'][$key]);
            }
        }
        if (($resource['hooks'] ?? []) === [] && ($resource['actions'] ?? []) === [] && ($resource['events'] ?? []) === []) {
            return null;
        }

        return $resource;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right @return array<string,mixed> */
    private function mergeResource(string $resourceKey, array $left, array $right): array
    {
        foreach (['hooks', 'events'] as $group) {
            foreach ((array) ($right[$group] ?? []) as $key => $bindings) {
                $left[$group][$key] = [
                    ...(array) ($left[$group][$key] ?? []),
                    ...(is_array($bindings) ? $bindings : []),
                ];
            }
        }
        foreach ((array) ($right['actions'] ?? []) as $key => $binding) {
            if (isset($left['actions'][$key])) {
                throw new RuntimeDiagnostic('action_binding_duplicate', 'Runtime Action binding is duplicated.', [
                    'resource_key' => $resourceKey,
                    'action_key' => $key,
                ]);
            }
            $left['actions'][$key] = $binding;
        }

        return $left;
    }

    /** @param mixed $binding @param array{type:string,code:string} $owner */
    private function belongsToOwner(mixed $binding, array $owner): bool
    {
        $bindingOwner = is_array($binding) && is_array($binding['owner'] ?? null) ? $binding['owner'] : [];

        return ($bindingOwner['type'] ?? null) === $owner['type'] && ($bindingOwner['code'] ?? null) === $owner['code'];
    }

    /** @param array<mixed> $value @return array<mixed> */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function assertSerializable(array $value): void
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                $this->assertSerializable($item);
                continue;
            }
            if (!is_scalar($item) && $item !== null) {
                throw new RuntimeDiagnostic('runtime_descriptor_invalid', 'Runtime descriptor contains a non-serializable value.');
            }
        }
    }
}
