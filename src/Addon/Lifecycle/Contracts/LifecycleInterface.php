<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle\Contracts;

use PTAdmin\Addon\Lifecycle\LifecycleContext;

/** 插件可选的安装、卸载、启用和禁用生命周期入口。 */
interface LifecycleInterface
{
    public function install(LifecycleContext $context): void;

    public function uninstall(LifecycleContext $context): void;

    public function enable(LifecycleContext $context): void;

    public function disable(LifecycleContext $context): void;
}
