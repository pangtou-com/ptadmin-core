<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

/**
 * 允许 Laravel ServiceProvider 进入插件 Runtime 的显式标记。
 *
 * Runtime 同时要求实现类继承 Laravel ServiceProvider，避免仅凭 manifest 类名加载任意容器扩展。
 */
interface ProviderInterface
{
}
