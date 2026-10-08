<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

/** 插件 Provider 主动注册业务能力；资源同步仅准备容器服务，不调用此入口。 */
interface CapabilityProviderInterface extends ProviderInterface
{
    /** 插件其余启动入口成功后调用，编码来自已验证的 manifest。 */
    public function registerCapabilities(string $addonCode): void;
}
