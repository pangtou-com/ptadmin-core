<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Resource;

/**
 * 插件生命周期与 Schema、配置定义、后台导航及业务权限之间的资源同步边界。
 *
 * 实现必须保留用户配置值和业务数据；禁用或移除声明时只停用资源定义。
 */
interface ResourceSynchronizerInterface
{
    /**
     * 同步插件当前声明；导航缺项会停用，业务权限退役必须显式声明 status=false。
     *
     * @return array{schemas:int,configs:int,dicts:int,notices:int,admin_menus:int,admin_resources:int}
     */
    public function sync(string $directory, string $code): array;

    /** 停用插件拥有的 Schema、配置定义、字典、导航和权限，不删除业务数据及授权。 */
    public function deactivate(string $code): void;
}
