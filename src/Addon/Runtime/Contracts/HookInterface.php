<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

use Illuminate\Contracts\Events\Dispatcher;

/** active 插件向 Laravel Event Dispatcher 注册事件监听器的固定入口。 */
interface HookInterface
{
    /**
     * 注册当前插件声明的监听器；插件停用后不会在新的应用生命周期中调用该入口。
     *
     * @param string $code 已验证 manifest 中的插件编码
     * @param array<string, mixed> $manifest 已完成 manifest 与文件清单验证的数据
     */
    public function register(Dispatcher $events, string $code, array $manifest): void;
}
