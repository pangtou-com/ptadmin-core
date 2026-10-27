<?php

declare(strict_types=1);

return [
    'title' => '工作概览',
    'description' => '查看个人动态、通知和常用功能。',
    'editable' => true,
    // 首屏指标使用 stats 组件，与下方可调整的组件布局独立。
    'overview_widget' => 'ptadmin.workspace-summary',
    // 留空时取授权菜单中的前八个功能；填写稳定菜单 key 可指定顺序。
    'shortcut_keys' => [],
    // 宿主可实现 DashboardDataProviderInterface，批量提供自有组件数据。
    'provider' => null,
    'widgets' => [
        'ptadmin.workspace-summary' => ['title' => '个人概览', 'type' => 'stats'],
        'ptadmin.operation-trend' => [
            'title' => '近七天操作', 'description' => '当前账号的每日操作次数', 'type' => 'trend',
            'default_layout' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
        ],
        'ptadmin.recent-operations' => [
            'title' => '最近操作', 'description' => '当前账号最近的操作记录', 'type' => 'list',
            'default_layout' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
        ],
        'ptadmin.quick-actions' => [
            'title' => '快捷入口', 'description' => '快速打开已授权的功能', 'type' => 'card',
            'default_layout' => ['x' => 0, 'y' => 4, 'w' => 12, 'h' => 3],
        ],
        'ptadmin.notifications' => [
            'title' => '未读通知', 'description' => '当前账号尚未阅读的站内通知', 'type' => 'list',
            'default_enabled' => false,
        ],
        'ptadmin.version-notices' => ['title' => '版本信息', 'type' => 'list', 'default_enabled' => false],
    ],
];
