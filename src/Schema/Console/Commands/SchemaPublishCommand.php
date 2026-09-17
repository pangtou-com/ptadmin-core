<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use Throwable;

final class SchemaPublishCommand extends Command
{
    protected $signature = 'schema:publish {resource : 要发布的资源编码} {--force : 批准并执行该资源的破坏性变更}';

    protected $description = '检查并发布指定代码型 Schema 的现有草稿';

    public function handle(SchemaFileSynchronizer $synchronizer): int
    {
        $resource = (string) $this->argument('resource');
        try {
            $result = $synchronizer->publish($resource, (bool) $this->option('force'));
            $this->components->info(sprintf(
                'Schema %s 已发布，风险等级：%s。',
                $result['resource'],
                $result['risk']
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
