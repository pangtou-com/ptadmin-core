<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;
use Throwable;

final class RuntimeCacheCommand extends Command
{
    protected $signature = 'runtime:cache {--clear : 清理 Runtime 缓存}';
    protected $description = '校验并重建 ptadmin-runtime.php';

    public function handle(RuntimeRegistry $registry, RuntimeCacheStore $cache, RuntimeRegistryValidator $validator): int
    {
        try {
            if ((bool) $this->option('clear')) {
                $cache->clear();
                $this->components->info('Runtime 缓存已清理。');

                return self::SUCCESS;
            }
            $snapshot = $registry->build();
            $diagnostics = $validator->validate($snapshot);
            if ($diagnostics !== []) {
                $this->components->error('Runtime 校验失败，未写入缓存。');

                return self::FAILURE;
            }
            $cache->write($snapshot);
            $this->components->info('Runtime 缓存已重建。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
