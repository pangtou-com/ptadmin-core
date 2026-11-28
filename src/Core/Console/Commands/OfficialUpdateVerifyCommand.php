<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Updates\OfficialUpdateInstaller;
use PTAdmin\Core\Updates\OfficialUpdateService;

/** 独立执行器在替换 vendor 前，通过旧应用再次校验官方发布状态。 */
final class OfficialUpdateVerifyCommand extends Command
{
    protected $signature = 'ptadmin:updates:verify-target {component} {version} {--commit=} {--build=} {--artifact=} {--publication-only}';
    protected $description = '只复核官方更新目标，不修改文件';
    protected $hidden = true;

    public function handle(OfficialUpdateInstaller $installer, OfficialUpdateService $updates): int
    {
        try {
            if ($this->option('publication-only')) {
                // 排空后应用已由本次任务维护；这里只读复核发行，不绕过安装入口的资格检查。
                $matches = array_values(array_filter($updates->fetchCatalog()['results'], fn (array $entry): bool =>
                    $entry['component'] === $this->argument('component') && $entry['version'] === $this->argument('version')));
                if (count($matches) !== 1) {
                    throw new \RuntimeException('目标发行已撤回或不可获取。');
                }
                $release = $matches[0];
            } else {
                $release = $installer->resolve($this->argument('component'), $this->argument('version'));
            }
            if ($this->option('commit') !== null && $this->option('commit') !== ($release['git_commit'] ?? null)) {
                throw new \RuntimeException('目标提交已变化，请重新发起更新。');
            }
            if ($this->argument('component') === 'core') {
                $application = $release['application'] ?? [];
                if ($this->option('build') !== ($application['target']['build_id'] ?? null)
                    || !in_array($this->option('artifact'), array_column($application['artifacts'] ?? [], 'sha256'), true)) {
                    throw new \RuntimeException('目标构建或归档已变化，请重新发起更新。');
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
