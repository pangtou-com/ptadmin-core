<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use PTAdmin\Schema\Meta\Models\ResourceVersion;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistrySnapshot;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;

/** 在插件生命周期的最终状态边界校验 Runtime Owner 贡献。 */
final class AddonRuntimeLifecycleValidator
{
    public function __construct(
        private RuntimeRegistry $registry,
        private RuntimeRegistryValidator $validator,
        private AddonRuntimeRegistrar $registrar,
    ) {
    }

    /** @param array<string,mixed> $manifest */
    public function assertActivated(string $directory, string $code, array $manifest): void
    {
        if (!$this->hasActiveSchema()) {
            return;
        }
        $candidateRegistry = new RuntimeRegistry();
        $this->registrar->contributeRuntime($directory, $code, $manifest, $candidateRegistry);
        $candidate = $candidateRegistry->build();
        $snapshot = $this->registry->build()->replaceOwner(['type' => 'addon', 'code' => strtolower($code)], $candidate);
        $this->assertValidForActiveResources($snapshot, array_keys((array) ($candidate->toArray()['resources'] ?? [])));
    }

    public function assertDeactivated(string $code): void
    {
        if (!$this->hasActiveSchema()) {
            return;
        }
        $snapshot = $this->registry->build()->replaceOwner(['type' => 'addon', 'code' => strtolower($code)], null);
        $this->assertValidForActiveResources($snapshot);
    }

    private function hasActiveSchema(): bool
    {
        return ResourceVersion::query()->where('status', 'active')->exists();
    }

    /** @param list<string> $additionalKeys */
    private function assertValidForActiveResources(RuntimeRegistrySnapshot $snapshot, array $additionalKeys = []): void
    {
        $activeKeys = Resource::query()
            ->where('status', '!=', 'disabled')
            ->pluck('resource_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
        $keys = array_values(array_unique([...$activeKeys, ...$additionalKeys]));
        sort($keys, SORT_STRING);
        $this->validator->assertValid($snapshot, $keys);
    }
}
