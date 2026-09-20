<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use PTAdmin\Schema\Meta\Models\ResourceVersion;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistrySnapshot;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;

/**
 * 在插件生命周期最终状态落定前校验 Schema Runtime Owner 贡献。
 *
 * 启用时使用候选 Registry 替换当前插件 Owner，禁用时移除该 Owner，再针对全部 active Schema 验证快照；
 * 校验失败会让上层生命周期操作回滚，而不会把部分有效的 Runtime 注册留在全局 Registry。
 */
final class RuntimeLifecycleValidator
{
    public function __construct(
        private RuntimeRegistry $registry,
        private RuntimeRegistryValidator $validator,
        private RuntimeRegistrar $registrar,
    ) {
    }

    /**
     * 预检插件启用后的 Runtime 快照，包含插件新增但尚未出现在数据库查询结果中的资源键。
     *
     * @param array<string,mixed> $manifest 已完成验证的插件 manifest
     */
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

    /** 预检移除插件 Owner 后，剩余 Runtime 是否仍满足全部 active Schema。 */
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
