<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Resource;

/**
 * 插件生命周期与 Schema、配置定义、后台菜单之间的资源同步边界。
 *
 * 实现必须保留用户配置值和业务数据；禁用或移除声明时只停用资源定义。
 */
interface ResourceSynchronizerInterface
{
    /**
     * 同步插件当前声明，并停用同一插件先前存在但本次已移除的定义。
     *
     * @return array{schemas:int,configs:int,dicts:int,admin_resources:int}
     */
    public function sync(string $directory, string $code): array;

    /** 停用插件拥有的 Schema、配置定义、字典和菜单资源，不删除配置值或资源数据。 */
    public function deactivate(string $code): void;
}
