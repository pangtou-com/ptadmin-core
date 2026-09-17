<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

use InvalidArgumentException;

/**
 * 配置分组是核心协议的一部分，后台只能使用这些固定编码，不能动态创建分组。
 */
final class ConfigGroups
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'general' => '常规',
            'security' => '安全',
            'auth' => '认证',
            'appearance' => '外观',
            'frontend' => '前端',
            'addon' => '插件',
        ];
    }

    public static function assert(string $group): string
    {
        if (!array_key_exists($group, self::all())) {
            throw new InvalidArgumentException('Configuration group is not supported: '.$group);
        }

        return $group;
    }
}
