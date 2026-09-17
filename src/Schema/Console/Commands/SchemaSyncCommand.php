<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use Throwable;

final class SchemaSyncCommand extends Command
{
    protected $signature = 'schema:sync {--draft : 只同步草稿，不检查或发布资源}';

    protected $description = '扫描 database/schemas 下的代码型 Schema，并按安全策略同步发布';

    public function handle(SchemaSourceDiscovery $discovery, SchemaFileSynchronizer $synchronizer): int
    {
        try {
            $files = $discovery->discover();
            if ($files === []) {
                $this->components->info('没有发现 Schema 文件。');

                return self::SUCCESS;
            }
            $results = $synchronizer->sync($files, (bool) $this->option('draft'));
            $this->table(
                ['资源', '状态', '风险', '文件'],
                array_map(static fn (array $result): array => [
                    $result['resource'],
                    $result['status'],
                    $result['risk'],
                    $result['path'],
                ], $results)
            );
            $blocked = array_filter($results, static fn (array $result): bool => $result['status'] === 'blocked');
            if ($blocked !== []) {
                $this->components->error('存在 guarded 或 destructive 变更，已保留为草稿。请检查后使用 schema:publish 发布指定资源。');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
