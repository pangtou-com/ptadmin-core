<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Updates\OfficialUpdateInstaller;

/** 独立执行器在替换 vendor 前，通过旧应用再次校验官方发布状态。 */
final class OfficialUpdateVerifyCommand extends Command
{
    protected $signature = 'ptadmin:updates:verify-target {component} {version} {--commit=}';
    protected $description = '只复核官方更新目标，不修改文件';
    protected $hidden = true;

    public function handle(OfficialUpdateInstaller $installer): int
    {
        try {
            $release = $installer->resolve($this->argument('component'), $this->argument('version'));
            if ($this->option('commit') !== null && $this->option('commit') !== ($release['git_commit'] ?? null)) {
                throw new \RuntimeException('目标提交已变化，请重新发起更新。');
            }
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
