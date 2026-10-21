<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;

final class AddonCacheCommand extends Command
{
    protected $signature = 'ptadmin:addon:cache';

    protected $description = '重新校验本地插件声明并原子发布注册快照，不改变插件启停状态';

    public function handle(AddonCatalog $catalog, RuntimeCacheStore $runtimeCache): int
    {
        try {
            $snapshot = $catalog->rebuild();
            $runtimeCache->clear();
            foreach ($snapshot['errors'] as $error) {
                $this->error($error['code'].': '.$error['message']);
            }
            $this->info('插件注册快照已更新，共 '.count($snapshot['addons']).' 个插件。');
            return $snapshot['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
