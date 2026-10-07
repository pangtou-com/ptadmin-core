<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use Illuminate\Database\DatabaseManager;
use PTAdmin\Core\Config\Models\ConfigDefinition;
use RuntimeException;

/**
 * 同步项目二次开发应用根目录 resources/config.*.json 声明。
 *
 * 宿主应用只有一个稳定归属 app/host；文件格式和插件配置声明一致，配置值与定义生命周期仍由 Core 管理。
 */
final class ApplicationConfigSynchronizer
{
    public function __construct(
        private ConfigService $configs,
        private DatabaseManager $database,
        private string $applicationPath,
    ) {
        $this->applicationPath = rtrim($applicationPath, '/');
    }

    /** @return array{synced:int,disabled:int} */
    public function sync(): array
    {
        return $this->database->transaction(function (): array {
            $keys = [];
            $synced = 0;
            foreach ($this->files() as $path) {
                foreach ($this->readDefinitions($path) as $definition) {
                    $definition['owner_type'] = 'app';
                    $definition['owner_code'] = 'host';
                    $this->configs->syncDefinition($definition);
                    $keys[] = (string) $definition['config_key'];
                    $synced++;
                }
            }

            $query = ConfigDefinition::query()->where([
                'owner_type' => 'app',
                'owner_code' => 'host',
            ]);
            if ($keys !== []) {
                $query->whereNotIn('config_key', array_values(array_unique($keys)));
            }
            $disabled = $query->where('status', true)->update(['status' => false, 'updated_at' => now()]);

            return ['synced' => $synced, 'disabled' => $disabled];
        });
    }

    /** @return list<string> */
    private function files(): array
    {
        $paths = glob($this->applicationPath.'/resources/config.*.json') ?: [];
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<array<string,mixed>> */
    private function readDefinitions(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('宿主应用配置声明不是有效 JSON：'.$path, 0, $exception);
        }
        $items = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new RuntimeException('宿主应用配置声明必须是对象或对象数组：'.$path);
            }
        }

        return $items;
    }
}
