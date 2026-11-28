<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 只检测当前账号，不启动 Composer，不推断 SSH/CI 账号也具有相同权限。 */
class UpdateEnvironment
{
    public function inspect(): array
    {
        $process = function_exists('proc_open');
        $paths = [
            'composer.json' => base_path('composer.json'), 'composer.lock' => base_path('composer.lock'),
            'vendor' => base_path('vendor'), '项目根目录' => base_path(),
            'storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $writable = [];
        foreach ($paths as $name => $path) {
            $writable[$name] = file_exists($path) ? is_writable($path) : is_writable(dirname($path));
        }
        $delivery = config('ptadmin-updates.delivery');
        if (!in_array($delivery, ['manual', 'git', 'ci'], true)) {
            $delivery = file_exists(base_path('.git')) ? 'git' : 'manual';
        }
        $dependency = (new DependencyBaseline())->inspect();
        return ['dependency' => array_diff_key($dependency, ['target' => true, 'source_snapshot' => true]), 'process_execution' => $process,
            'writable' => $writable, 'delivery' => $delivery, 'online_core_update' => $dependency['status'] === 'standard' && $process && class_exists(\ZipArchive::class) && is_executable(config('ptadmin-updates.php_binary')) && !in_array(false, $writable, true) && $delivery === 'manual',
            'scope' => '仅检测当前 PHP 运行账号的 PATH 与文件权限，未执行 Composer；SSH、容器与 CI 的环境可能不同。'];
    }

    public function guidance(array $environment, ?string $target, bool $canManage): array
    {
        $issues = [];
        if (!$canManage) {
            $issues[] = ['code' => 'permission_required', 'message' => '当前账号不能发起官方版本检查，请联系有权限的管理员；实际部署还需要服务器或 CI 的部署权限。'];
        }
        if ($environment['dependency']['status'] !== 'standard') {
            $issues[] = ['code' => 'dependency_'.$environment['dependency']['status'], 'message' => $environment['dependency']['reason']];
        }
        if (!is_executable(config('ptadmin-updates.php_binary'))) {
            $issues[] = ['code' => 'php_cli_unavailable', 'message' => '配置的 PHP CLI 不可执行，请核对 PTADMIN_UPDATE_PHP_BINARY 的路径与权限；也可由部署负责人在终端使用匹配版本的 PHP 更新。'];
        }
        if (!$environment['process_execution']) {
            $issues[] = ['code' => 'process_disabled', 'message' => '当前 PHP 禁止启动进程。无需为网页更新开放执行权限；请通过 SSH、主机面板或 CI 执行依赖构建，再部署验证后的结果。'];
        }
        $readonly = array_keys(array_filter($environment['writable'], static fn (bool $value): bool => !$value));
        if ($readonly !== []) {
            $issues[] = ['code' => 'deployment_not_writable', 'message' => '当前 PHP 账号不可写：'.implode('、', $readonly).'。这可能是正常的只读部署；请使用有权部署账号或 CI 更新，必要时由运维调整所有者及最小权限，不要设置 777。'];
        }
        if (in_array($environment['delivery'], ['git', 'ci'], true)) {
            $issues[] = ['code' => 'managed_delivery', 'message' => '当前项目采用 Git/CI 交付，请在源码分支更新依赖并提交 composer.lock，通过现有流水线部署，避免线上文件与仓库不一致。'];
        }
        $commands = [];
        if ($target !== null && ReleaseVersion::valid($target) && ReleaseVersion::stable($target)) {
            $commands = [
                'composer require "ptadmin/core:'.$target.'" --no-update',
                'composer update ptadmin/core --with-all-dependencies',
                'composer check-platform-reqs --no-dev',
            ];
        }
        return ['mode' => 'maintainer_deployment', 'target_version' => $commands !== [] ? $target : null, 'issues' => $issues,
            'summary' => '官方标准依赖使用完整包或补丁更新，无需 Composer；自定义依赖及 Git/CI 项目按以下流程处理。',
            'commands' => $commands, 'command_context' => '在包含 composer.json 的项目源码根目录或隔离构建环境执行，使用有部署权限的终端账号；先备份并阅读目标版本说明。后台不执行以下 Composer 命令。',
            'steps' => [
                '先备份数据库、.env、用户上传文件与当前部署版本，并阅读目标版本的兼容要求、升级步骤及恢复说明。',
                '在有 Composer 的开发环境或 CI 更新 Core 约束和锁文件，测试宿主业务、插件及 Shell；依赖冲突时根据 Composer 报错调整，不使用 --ignore-platform-reqs 绕过要求。',
                '服务器有 Composer：按部署流程执行 composer install --no-dev --prefer-dist --optimize-autoloader，从已提交的锁文件安装，不在正式机直接运行全量 composer update。',
                '服务器没有 Composer 或禁止执行命令：在与目标 PHP、扩展、操作系统及架构兼容的环境构建，交付包含 vendor 的完整项目部署产物；不能只复制 ptadmin/core 一个目录。',
                '通过有权部署账号或 CI 发布。保留线上 .env、storage、上传文件、应用身份及安装记录；只读镜像应重建镜像，不能临时开放网页写 vendor。',
                '依赖部署后，在维护窗口按版本说明执行 php artisan optimize:clear、php artisan migrate --force、php artisan ptadmin:sync，并按原部署配置重建缓存、重启 PHP 和队列；全部验证完成再退出维护。没有 SSH 时由主机面板或运维执行。',
                '核对实际 Core 与 Shell 版本、登录、菜单、插件和业务功能，随后重新检查官方版本。数据库迁移不可逆时须按升级前备份恢复，回退代码不能恢复数据库。',
            ]];
    }
}
