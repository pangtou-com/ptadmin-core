<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use PTAdmin\Schema\Runtime\RuntimeContributor;

/** 插件声明 Runtime 贡献时使用的入口。Owner 由 Core 固定，插件不能替换。 */
interface AddonRuntimeRegistrarInterface
{
    /** @param array<string, mixed> $manifest */
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void;
}
