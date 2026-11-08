<?php

declare(strict_types=1);

// 由独立更新进程调用；只在迁移及同步完成后启动新应用，避免依赖目标版本新增命令。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (ltrim(\Composer\InstalledVersions::getPrettyVersion('ptadmin/core') ?? '', 'v') !== $argv[2]) {
    throw new RuntimeException('更新后的 Core 版本不匹配。');
}
$app->make('db')->connection()->getPdo();
(new \PTAdmin\Core\Install\AdminFrontendManifest($argv[2], (int) config('ptadmin.api_protocol'), (int) config('ptadmin.schema_protocol')))
    ->verify(public_path(config('ptadmin.web_prefix')));
