<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Core\Install\InstallStateStore;
use Throwable;

final class AddonSetupCommand extends Command
{
    protected $signature = 'ptadmin:addon:setup {code : 插件编码} {--enable : 显式恢复用户主动禁用的插件}';

    protected $description = '幂等同步已有本地插件的资源、Runtime 和前端模块';

    public function handle(Installer $installer, InstallStateStore $installState): int
    {
        if (!$installState->isInstalled()) {
            $this->error('PTAdmin 尚未完成应用安装，不能执行插件 Setup。');

            return self::FAILURE;
        }

        $code = (string) $this->argument('code');
        try {
            $result = $installer->setup($code, (string) config('ptadmin.web_prefix', 'admin'), (bool) $this->option('enable'));
        } catch (Throwable $exception) {
            $this->error(sprintf('插件 %s Setup 失败：%s', $code, $exception->getMessage()));

            return self::FAILURE;
        }

        $resources = is_array($result['resources'] ?? null) ? $result['resources'] : [];
        $this->info(sprintf(
            '插件 %s Setup 完成：state=%s，frontend=%s，Schema=%d，配置=%d，字典=%d，后台资源=%d。',
            (string) ($result['code'] ?? $code),
            (string) ($result['state'] ?? 'unknown'),
            ($result['frontend'] ?? false) ? 'published' : 'unchanged',
            (int) ($resources['schemas'] ?? 0),
            (int) ($resources['configs'] ?? 0),
            (int) ($resources['dicts'] ?? 0),
            (int) ($resources['admin_resources'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
