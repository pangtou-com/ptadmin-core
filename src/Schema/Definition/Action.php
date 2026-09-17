<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

/**
 * 动作定义的归一化器，统一 enabled、label 和参数结构后再参与 checksum。
 */
final class Action
{
    /** @param array<string, mixed> $action */
    public static function normalize(array $action): array
    {
        return $action;
    }

    /** @param array<string, mixed> $actions */
    public static function enabled(array $actions, string $key): bool
    {
        if (!array_key_exists($key, $actions)) {
            return true;
        }
        return !array_key_exists('enabled', (array) $actions[$key]) || (bool) $actions[$key]['enabled'];
    }

    /** @param array<string, mixed> $schema */
    public static function effectiveKeys(array $schema): array
    {
        $keys = ['list', 'view', 'create', 'update', 'delete'];
        $capabilities = (array) ($schema['capabilities'] ?? []);
        if (!empty($capabilities['allow_recycle'])) {
            $keys[] = 'restore';
            $keys[] = 'purge';
        }
        foreach (['allow_import' => 'import', 'allow_export' => 'export', 'allow_duplicate' => 'duplicate', 'allow_print' => 'print'] as $capability => $key) {
            if (!empty($capabilities[$capability])) {
                $keys[] = $key;
            }
        }
        if (!empty($capabilities['track_mode'])) {
            $keys[] = 'view_track';
        }
        foreach ((array) ($schema['actions'] ?? []) as $key => $action) {
            if (!self::enabled((array) $schema['actions'], (string) $key)) {
                $keys = array_values(array_diff($keys, [(string) $key]));
                continue;
            }
            if ($key !== '') {
                $keys[] = (string) $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }
}
