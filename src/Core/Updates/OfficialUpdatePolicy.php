<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 同一策略用于界面能力提示和执行前复核，不能由浏览器指定下载地址或部署目录。 */
final class OfficialUpdatePolicy
{
    public function __construct(private readonly UpdateEnvironment $environment)
    {
    }

    public function blockers(string $component, array $versions): array
    {
        $environment = $this->environment->inspect();
        $reasons = [];
        if (!config('ptadmin-updates.enabled')) {
            $reasons[] = '官方联网检查已关闭。';
        }
        if (in_array($environment['delivery'], ['git', 'ci'], true)) {
            $reasons[] = '当前应用由 Git/CI 交付，请通过原部署流程更新。';
        }
        if (!$versions[$component]['known']) {
            $reasons[] = '当前版本身份不明确或使用本地开发包，请通过项目部署流程更新。';
        }
        if (!is_writable(storage_path()) || !is_writable(base_path())) {
            $reasons[] = '更新工作目录不可写，请由部署负责人处理。';
        }
        if ($component === 'admin-shell') {
            if ($versions['admin-shell']['custom'] || $versions['admin-shell']['integrity'] !== 'verified') {
                $reasons[] = '当前 Shell 为自定义版本或文件已修改，请合并源码后部署。';
            }
            if (!class_exists(\ZipArchive::class)) {
                $reasons[] = '当前 PHP 缺少 ZIP 扩展。';
            }
            if (!is_writable(public_path()) || !is_writable(public_path(config('ptadmin.web_prefix')))) {
                $reasons[] = '后台前端目录不可写。';
            }
        } else {
            if (realpath(storage_path()) !== realpath(base_path('storage'))
                || app()->getCachedConfigPath() !== base_path('bootstrap/cache/config.php')
                || app()->getCachedRoutesPath() !== base_path('bootstrap/cache/routes-v7.php')) {
                $reasons[] = '自定义 storage 或缓存目录请通过项目部署流程更新。';
            }
            $entry = is_file(public_path('index.php')) ? file_get_contents(public_path('index.php')) : '';
            $maintenancePosition = strpos($entry, 'maintenance.php');
            $autoloadPosition = strpos($entry, 'vendor/autoload.php');
            if ($maintenancePosition === false || $autoloadPosition === false || $maintenancePosition > $autoloadPosition) {
                $reasons[] = '公开入口未在加载 vendor 前处理维护页面，请通过部署流程更新。';
            }
            if (ini_get('opcache.enable') && !ini_get('opcache.validate_timestamps')) {
                $reasons[] = 'PHP OPcache 未检查文件变化，请通过能够重启 PHP 的部署流程更新。';
            }
            if (getenv('COMPOSER') || getenv('COMPOSER_VENDOR_DIR') || getenv('COMPOSER_BIN_DIR')) {
                $reasons[] = 'Composer 环境变量修改了项目布局，请通过部署流程更新。';
            }
            if (!$environment['online_core_update']) {
                $reasons[] = 'Core 在线更新需要可执行的 PHP CLI、Composer、进程能力和可写依赖目录。';
            }
            $composer = is_file(base_path('composer.json')) ? UpdateFiles::read(base_path('composer.json')) : [];
            if (isset($composer['config']['vendor-dir']) && $composer['config']['vendor-dir'] !== 'vendor') {
                $reasons[] = '自定义 vendor 目录请通过项目部署流程更新。';
            }
            // 自定义安装脚本和 Composer 插件必须在宿主自己的部署流程运行。
            foreach ($composer['scripts'] ?? [] as $event => $scripts) {
                if (!in_array($event, ['pre-install-cmd', 'post-install-cmd', 'pre-update-cmd', 'post-update-cmd', 'pre-autoload-dump', 'post-autoload-dump'], true)) {
                    continue;
                }
                foreach ((array) $scripts as $script) {
                    if (!in_array($script, ['Illuminate\\Foundation\\ComposerScripts::postAutoloadDump', '@php artisan package:discover --ansi'], true)) {
                        $reasons[] = '项目具有自定义 Composer 生命周期脚本，请通过原部署流程更新。';
                    }
                }
            }
            $lock = is_file(base_path('composer.lock')) ? UpdateFiles::read(base_path('composer.lock')) : [];
            foreach ($lock['packages'] ?? [] as $package) {
                if (($package['type'] ?? '') === 'composer-plugin') {
                    $reasons[] = '项目依赖 Composer 插件，请通过原部署流程更新。';
                }
            }
            if (isset($composer['config']['bin-dir']) || config('app.maintenance.driver', 'file') !== 'file'
                || isset($lock['packages']) && collect($lock['packages'])->contains('name', 'laravel/octane')) {
                $reasons[] = '自定义依赖布局、共享维护模式或常驻 HTTP 进程请通过项目部署流程更新。';
            }
            foreach ($composer['repositories'] ?? [] as $repository) {
                if (is_array($repository) && ($repository['type'] ?? null) === 'path') {
                    $reasons[] = '项目包含本地路径依赖，请在构建环境更新。';
                }
            }
            if (!is_file(base_path('composer.lock')) || !is_dir(base_path('vendor')) || is_link(base_path('vendor'))) {
                $reasons[] = '缺少正式锁文件或 vendor 使用符号链接，请通过项目部署流程更新。';
            }
            if (is_file(storage_path('framework/down')) || is_file(storage_path('framework/maintenance.php'))) {
                $reasons[] = '应用已处于维护状态，请先完成原维护操作。';
            }
        }
        foreach (glob(config('ptadmin-updates.path').'/operations/*/journal.json') ?: [] as $path) {
            $journal = UpdateFiles::read($path);
            if (!in_array($journal['status'] ?? '', ['completed', 'restored'], true)) {
                $reasons[] = '存在未恢复的更新，请先使用该任务的恢复工具处理。';
                break;
            }
        }
        return array_values(array_unique($reasons));
    }
}
