<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use RuntimeException;
use Throwable;

final class AdminSyncCommand extends Command
{
    protected $signature = 'ptadmin:admin:sync';

    protected $description = '在数据库迁移后同步 PTAdmin 内置 Schema、配置和权限资源';

    public function __construct(
        private SchemaSourceDiscovery $schemaSources,
        private SchemaFileSynchronizer $schemaSynchronizer,
        private InstallService $install,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach (['admins', 'admin_resources', 'admin_roles', 'schema_resources', 'ptadmin_config_definitions'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error(sprintf('缺少数据表 %s，请先执行 php artisan migrate。', $table));

                return self::FAILURE;
            }
        }

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
            $authorization = $this->install->syncAuthorizationResources();
            $configDefinitions = $this->install->syncConfigDefinitions();
        } catch (Throwable $exception) {
            $this->error('后台内置资源同步失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '后台内置资源同步完成：Schema %d 个，权限资源 %d 个，配置定义 %d 个。',
            count($schemaResources),
            $authorization['resource_count'],
            $configDefinitions,
        ));

        return self::SUCCESS;
    }
}
