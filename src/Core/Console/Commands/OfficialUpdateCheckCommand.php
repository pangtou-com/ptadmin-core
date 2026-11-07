<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Updates\OfficialUpdateService;
use PTAdmin\Core\Updates\UpdateException;

final class OfficialUpdateCheckCommand extends Command
{
    protected $signature = 'ptadmin:updates:check {--scheduled : 按本地错峰及退避时间执行}';
    protected $description = '检查官方签名目录与安全公告，不执行依赖更新或替换文件';

    public function handle(OfficialUpdateService $updates, InstallStateStore $installation): int
    {
        if (!$installation->isInstalled()) {
            $this->error('请先完成安装，再检查官方版本。');
            return self::FAILURE;
        }
        if ($this->option('scheduled') && !config('ptadmin-updates.enabled')) {
            return self::SUCCESS;
        }
        try {
            $updates->check((bool) $this->option('scheduled'));
            $result = $updates->overview(0, true);
            if ($result['check']['error'] !== null) {
                $this->error($result['check']['error']['message']);
                return self::FAILURE;
            }
            $this->info('官方版本检查完成；具体版本、兼容要求和人工部署步骤请在后台“系统信息 → 版本与更新”查看。');
            return self::SUCCESS;
        } catch (UpdateException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
