<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Notice\NoticeSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use RuntimeException;
use Throwable;

final class AdminSyncCommand extends Command
{
    protected $signature = 'ptadmin:admin:sync';

    protected $description = '在数据库迁移后同步 PTAdmin 内置 Schema、通知、配置、字典和权限资源';

    public function __construct(
        private SchemaSourceDiscovery $schemaSources,
        private SchemaFileSynchronizer $schemaSynchronizer,
        private InstallService $install,
        private NoticeSynchronizer $notices,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $schemaResources = $this->schemaSynchronizer->sync($this->schemaSources->discoverCore());
            $blockedSchemas = array_values(array_filter(
                $schemaResources,
                static fn (array $result): bool => $result['status'] === 'blocked'
            ));
            if ($blockedSchemas !== []) {
                $blocked = $blockedSchemas[0];
                throw new RuntimeException(sprintf(
                    '内置 Schema 发布被阻止：%s（%s）。',
                    $blocked['resource'],
                    $blocked['risk'],
                ));
            }
            $noticeDefinitions = $this->notices->syncCore();
            $authorization = $this->install->syncAuthorizationResources();
            $configDefinitions = $this->install->syncConfigDefinitions();
            $dictionaryDefinitions = $this->install->syncDictionaryDefinitions();
        } catch (Throwable $exception) {
            $this->error('后台内置资源同步失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '后台内置资源同步完成：Schema %d 个，通知定义 %d 个，权限资源 %d 个，配置定义 %d 个，字典定义 %d 个。',
            count($schemaResources),
            $noticeDefinitions,
            $authorization['resource_count'],
            $configDefinitions,
            $dictionaryDefinitions,
        ));

        return self::SUCCESS;
    }
}
