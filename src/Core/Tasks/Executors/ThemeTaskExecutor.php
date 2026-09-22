<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks\Executors;

use PTAdmin\Core\Tasks\TaskExecutorInterface;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Theme\ThemeInstaller;
use PTAdmin\Core\Theme\ThemeLifecycle;
use PTAdmin\Core\Theme\ThemeCatalog;
use PTAdmin\Core\Theme\ThemeDataPackImporter;
use PTAdmin\Core\Theme\ThemeDataPackValidator;
use PTAdmin\Core\Theme\ThemeException;

final class ThemeTaskExecutor implements TaskExecutorInterface
{
    public function __construct(
        private ThemeInstaller $installer,
        private ThemeLifecycle $lifecycle,
        private ThemeCatalog $catalog,
        private ThemeDataPackValidator $dataPackValidator,
        private ThemeDataPackImporter $dataPackImporter,
    )
    {
    }

    /** @return array<string,mixed> */
    public function execute(array $task, TaskManager $tasks): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $type = (string) ($task['type'] ?? '');
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $tasks->heartbeat($taskId, 'validating', 10, 'Validating theme package.');
        $tasks->heartbeat($taskId, 'staging', 25, 'Staging theme package.');
        if ($type === TaskManager::SITE_THEME_ACTIVATE) {
            $tasks->heartbeat($taskId, 'activating', 60, 'Activating theme.');
            $result = $this->lifecycle->activate((string) ($payload['code'] ?? ''), (string) ($payload['version'] ?? ''));
        } elseif ($type === TaskManager::SITE_THEME_REMOVE) {
            $tasks->heartbeat($taskId, 'removing', 60, 'Removing theme.');
            $result = $this->lifecycle->remove((string) ($payload['code'] ?? ''), (string) ($payload['version'] ?? ''));
        } else {
            $result = $this->installer->install(
                (string) ($payload['source'] ?? ''),
                isset($payload['code']) ? (string) $payload['code'] : null,
                isset($payload['version']) ? (string) $payload['version'] : null,
            );
            $tasks->heartbeat($taskId, 'publishing', 90, 'Publishing theme assets.', $result);
            $selected = $payload['data_packs'] ?? [];
            if ($selected !== []) {
                if (!is_array($selected) || !array_is_list($selected)) {
                    throw new ThemeException('Theme data pack selection must be an array.', 'invalid_theme_data_pack_selection');
                }
                $tasks->heartbeat($taskId, 'importing_data', 95, 'Importing theme data packs.');
                $theme = $this->catalog->find((string) $result['code'], (string) $result['version']);
                if ($theme === null) {
                    throw new ThemeException('Installed theme cannot be resolved for data import.', 'theme_unavailable');
                }
                $packs = $this->dataPackValidator->validate($theme->manifest['data_packs'] ?? null, $theme->directory);
                $byCode = [];
                foreach ($packs as $pack) {
                    $byCode[$pack->code] = $pack;
                }
                $imports = [];
                foreach ($selected as $code) {
                    if (!is_string($code) || !isset($byCode[$code])) {
                        throw new ThemeException('Selected theme data pack is not declared.', 'theme_data_pack_not_found', ['code' => $code]);
                    }
                    $import = $this->dataPackImporter->import($byCode[$code]);
                    $imports[$code] = $import->toArray();
                    if (in_array($import->status, ['unavailable', 'failed'], true)) {
                        throw new ThemeException('Theme data pack import failed.', 'theme_data_pack_import_failed', ['code' => $code, 'result' => $import->toArray()]);
                    }
                }
                $result['data_packs'] = $imports;
            }
        }
        if ($type !== TaskManager::SITE_THEME_INSTALL) {
            $tasks->heartbeat($taskId, 'publishing', 90, 'Publishing theme assets.', $result);
        }

        return $result;
    }
}
