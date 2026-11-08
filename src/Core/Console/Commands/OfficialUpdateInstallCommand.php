<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Updates\OfficialUpdateInstaller;

final class OfficialUpdateInstallCommand extends Command
{
    protected $signature = 'ptadmin:updates:install {component : core 或 admin-shell} {version : 精确稳定版本} {--database-backup-confirmed : 已备份数据库并确认恢复步骤}';
    protected $description = '复核官方目标版本并执行文件更新任务';

    public function handle(OfficialUpdateInstaller $installer, InstallStateStore $installation): int
    {
        if (!$installation->isInstalled()) {
            $this->error('请先完成安装。');
            return self::FAILURE;
        }
        try {
            $task = $installer->submit($this->argument('component'), $this->argument('version'), 0, null, (bool) $this->option('database-backup-confirmed'));
            $this->info('更新任务：'.$task['task_id']);
            return $this->call('ptadmin:task:run', ['task_id' => $task['task_id']]);
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
