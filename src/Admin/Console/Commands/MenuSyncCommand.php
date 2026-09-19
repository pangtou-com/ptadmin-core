<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use Throwable;

final class MenuSyncCommand extends Command
{
    protected $signature = 'ptadmin:menu:sync';

    protected $description = '同步 Core 和插件 Resources/menu.json 到 admin_resources';

    public function __construct(private MenuSynchronizer $menus)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!Schema::hasTable('admin_resources')) {
            $this->error('缺少数据表 admin_resources，请先执行 php artisan migrate。');

            return self::FAILURE;
        }

        try {
            $result = $this->menus->syncAll();
        } catch (Throwable $exception) {
            $this->error('菜单资源同步失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $totals = $result['totals'];
        $this->info(sprintf(
            '菜单资源同步完成：新增 %d，更新 %d，无变化 %d，停用 %d。',
            $totals['created'],
            $totals['updated'],
            $totals['unchanged'],
            $totals['disabled'],
        ));

        return self::SUCCESS;
    }
}
