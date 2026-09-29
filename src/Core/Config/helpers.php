<?php

declare(strict_types=1);

use PTAdmin\Core\Config\ConfigService;

if (!function_exists('ptadmin_config')) {
    /** 读取配置组的生效值，或读取组内单个字段；配置编码可包含点号。 */
    function ptadmin_config(string $configKey, ?string $field = null, string $ownerType = 'core', string $ownerCode = 'ptadmin'): mixed
    {
        $values = app(ConfigService::class)->get($ownerType, $ownerCode, $configKey)['effective_values'];

        return $field === null ? $values : ($values[$field] ?? null);
    }
}
