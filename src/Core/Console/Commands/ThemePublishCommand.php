<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Theme\ActiveThemeResolver;
use PTAdmin\Core\Theme\ThemeLifecycle;
use Throwable;

final class ThemePublishCommand extends Command
{
    protected $signature = 'ptadmin:theme:publish {--force : 已确认替换当前主题的公开资源，跳过交互确认}';

    protected $description = '校验并重新发布当前启用主题的静态资源，保留旧目录备份';

    public function handle(InstallStateStore $state, ActiveThemeResolver $active, ThemeLifecycle $themes): int
    {
        if (!$state->isInstalled()) {
            $this->error('请先完成 PTAdmin 安装。');
            return self::FAILURE;
        }
        try {
            $active->reset();
            $theme = $active->active();
            if ($theme === null) {
                $this->error('没有可发布的有效当前主题，请检查主题配置与 theme.json。');
                return self::FAILURE;
            }
            $this->line('发布主题资源：'.$theme->identifier());
            if (!$this->option('force') && (!$this->input->isInteractive()
                || !$this->confirm('将以主题源码替换公开资源并备份旧目录，是否继续？'))) {
                $this->warn('未发布资源；自动部署请显式使用 --force。');
                return self::FAILURE;
            }
            $result = $themes->publishAssets($theme->code, $theme->version);
            $this->info('主题资源发布完成，共 '.$result['files'].' 个文件。');
            if ($result['backup_id'] !== null) {
                $this->line('原资源备份：'.storage_path('app/ptadmin/themes/operations/backups/'.$result['backup_id']));
            }
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('主题资源发布失败：'.$error->getMessage());
            return self::FAILURE;
        }
    }
}
