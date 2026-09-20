<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

use Illuminate\Contracts\Foundation\Application;

/**
 * active 插件的启动入口，用于注册插件自身服务及需要容器参与的运行时集成。
 *
 * Core 在 manifest 和生命周期校验通过后调用该入口；实现不应在文件加载阶段产生全局副作用。
 */
interface BootstrapInterface
{
    /**
     * 注册当前插件的启动逻辑。
     *
     * @param string $code 已验证 manifest 中的插件编码，不使用目录名替代
     * @param array<string, mixed> $manifest 已完成 manifest 与文件清单验证的数据
     */
    public function register(Application $app, string $code, array $manifest): void;
}
