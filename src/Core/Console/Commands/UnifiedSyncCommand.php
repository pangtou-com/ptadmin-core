<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Core\Config\ApplicationConfigSynchronizer;
use PTAdmin\Core\Install\InstallService;
use PTAdmin\Notice\NoticeSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaFileSynchronizer;
use PTAdmin\Schema\SchemaFile\SchemaSourceDiscovery;
use RuntimeException;
use Throwable;

/**
 * 统一同步 Core、宿主应用和已启用插件的代码资源。
 *
 * 数据库迁移、前端构建和插件生命周期不属于此命令；危险 Schema 变更也不会被静默发布。
 */
final class UnifiedSyncCommand extends Command
{
    protected $signature = 'ptadmin:sync';

    protected $description = '统一同步 Core、宿主应用和已启用插件资源';

    public function __construct(
        private readonly SchemaSourceDiscovery         $schemaSources,
        private readonly SchemaFileSynchronizer        $schemaSynchronizer,
        private readonly InstallService                $install,
        private readonly ApplicationConfigSynchronizer $applicationConfigs,
        private readonly MenuSynchronizer              $menus,
        private readonly AddonCatalog                  $addons,
        private readonly ResourceSynchronizerInterface $addonResources,
        private readonly NoticeSynchronizer            $notices,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $core = $this->syncCore();
            $application = $this->syncApplication();
            $addons = $this->syncAddons();
            $menus = $this->menus->syncAll();
        } catch (Throwable $exception) {
            $this->error('统一资源同步失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '统一资源同步完成：Core Schema=%d，宿主 Schema=%d，宿主配置=%d，插件=%d，菜单新增/更新/停用=%d/%d/%d。',
            $core,
            $application['schemas'],
            $application['configs'],
            $addons,
            $menus['totals']['created'],
            $menus['totals']['updated'],
            $menus['totals']['disabled'],
        ));

        return self::SUCCESS;
    }

    private function syncCore(): int
    {
        $results = $this->schemaSynchronizer->sync($this->schemaSources->discoverCore(), false, false);
        $this->assertNoBlockedSchemas($results, 'Core');
        $this->install->syncAuthorizationResources();
        $this->install->syncConfigDefinitions();
        $this->install->syncDictionaryDefinitions();
        $this->notices->syncCore();

        return count($results);
    }

    /** @return array{schemas:int,configs:int} */
    private function syncApplication(): array
    {
        $schemas = array_values(array_filter(
            $this->schemaSources->discover(),
            static fn (array $file): bool => $file['owner_type'] === 'app' && $file['owner_code'] === 'host',
        ));
        $results = $this->schemaSynchronizer->sync($schemas, false, false);
        $this->assertNoBlockedSchemas($results, '宿主应用');
        $configs = $this->applicationConfigs->sync();
        $this->notices->syncApplication(base_path());

        return ['schemas' => count($results), 'configs' => $configs['synced']];
    }

    private function syncAddons(): int
    {
        $count = 0;
        $discovery = $this->addons->scan();
        if ($discovery['errors'] !== []) {
            throw new RuntimeException('插件发现失败，不能执行统一同步。');
        }
        foreach ($discovery['addons'] as $addon) {
            if (($addon['state'] ?? null) !== 'active') {
                continue;
            }
            $directory = rtrim((string) config('ptadmin.addon.path', base_path('addons')), '/').'/'.$addon['code'];
            $this->addonResources->sync($directory, (string) $addon['code']);
            $count++;
        }

        return $count;
    }

    /** @param list<array{resource:string,status:string}> $results */
    private function assertNoBlockedSchemas(array $results, string $owner): void
    {
        foreach ($results as $result) {
            if ($result['status'] === 'blocked') {
                throw new RuntimeException(sprintf('%s Schema %s 需要显式发布确认。', $owner, (string) $result['resource']));
            }
        }
    }
}
