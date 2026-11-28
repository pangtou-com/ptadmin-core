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
    // 标准包更新只执行 PHP CLI，不运行 Composer。
    'php_binary' => env('PTADMIN_UPDATE_PHP_BINARY', PHP_BINDIR.'/php'),
    'max_archive_bytes' => 536870912,
    'max_uncompressed_bytes' => 1073741824,
    'process_timeout' => 1800,
];
