<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

use PTAdmin\Schema\Runtime\RuntimeContributor;

/**
 * 插件声明 Schema Runtime Action、Hook 和 Event 贡献时使用的入口。
 *
 * Core 传入已经绑定到当前插件的 RuntimeContributor，Owner 由 Core 固定，插件不能冒充 host 或其他插件。
 */
interface RuntimeRegistrarInterface
{
    /** @param array<string, mixed> $manifest 已完成 manifest 与文件清单验证的数据 */
    public function registerRuntime(RuntimeContributor $runtime, array $manifest): void;
}
