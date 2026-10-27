<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use PTAdmin\Admin\Models\Admin;

interface DashboardDataProviderInterface
{
    /**
     * 批量查询已通过组件访问检查的编码，返回以组件编码为键的 stats/trend/list/card/ranking 数据。
     * 未返回的 Core 编码使用内置数据；插件只接收自己的编码，不能返回其他归属的数据。
     * 业务数据范围由提供器按当前管理员限制，可见权限不能替代业务数据授权。
     *
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    public function query(Admin $admin, array $codes): array;
}
