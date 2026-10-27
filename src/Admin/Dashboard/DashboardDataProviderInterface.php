<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use PTAdmin\Admin\Models\Admin;

interface DashboardDataProviderInterface
{
    /**
     * 批量查询已通过组件访问检查的编码，返回以组件编码为键的 stats/trend/list/card/ranking 数据。
     * 未返回的编码使用 Core 内置数据；业务数据范围仍由宿主提供器按当前管理员限制。
     *
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    public function query(Admin $admin, array $codes): array;
}
