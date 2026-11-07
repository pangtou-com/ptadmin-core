<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PTADMIN_OFFICIAL_UPDATES_ENABLED', true),
    'path' => storage_path('app/ptadmin/updates'),
    // 公钥必须由维护者通过可信渠道部署，不能从待验签响应中自动导入。
    'trusted_keys' => [],
    'public_key_id' => env('PTADMIN_OFFICIAL_RELEASE_KEY_ID', ''),
    'public_key_path' => env('PTADMIN_OFFICIAL_RELEASE_PUBLIC_KEY_PATH', ''),
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
