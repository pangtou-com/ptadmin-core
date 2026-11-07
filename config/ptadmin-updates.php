<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PTADMIN_OFFICIAL_UPDATES_ENABLED', true),
    'path' => storage_path('app/ptadmin/updates'),
    // auto 仅识别 Git 目录；没有 Git 也不代表允许后台直接更新。
    'delivery' => env('PTADMIN_UPDATE_DELIVERY', 'auto'),
    'shell_custom' => (bool) env('PTADMIN_SHELL_CUSTOM', false),
    'shell_upstream_version' => env('PTADMIN_SHELL_UPSTREAM_VERSION', ''),
    // 基础壳创建来源由维护者明确记录，不用当前 Core 版本猜测。
    'template_version' => env('PTADMIN_TEMPLATE_VERSION', ''),
    'host_version' => env('PTADMIN_HOST_VERSION', ''),
    // 可指定 composer 可执行文件或 composer.phar；仅检查存在性，不自动执行。
    'composer_path' => env('PTADMIN_COMPOSER_PATH', ''),
];
