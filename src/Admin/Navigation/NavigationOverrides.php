<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Navigation;

/** 只保留后台实际调整的展示字段，代码仍维护导航身份与权限绑定。 */
final class NavigationOverrides
{
    private const COLUMNS = ['title', 'parent_key', 'path', 'icon', 'sort', 'is_menu', 'status'];

    /** @param array<string,mixed> $current @param array<string,mixed> $next @param array<string,mixed> $meta @return array<string,mixed> */
    public static function record(array $current, array $next, array $meta): array
    {
        if (!in_array($meta['owner_type'] ?? null, ['core', 'app', 'addon'], true)) {
            return $meta;
        }
        $overrides = $meta['navigation_overrides'] ?? [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $next) && $next[$column] != $current[$column]) {
                $overrides[$column] = $next[$column];
            }
        }
        $currentMeta = json_decode($current['meta_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($meta['external_url'] ?? '') !== ($currentMeta['external_url'] ?? '')) {
            $overrides['external_url'] = $meta['external_url'] ?? '';
        }
        if ($overrides !== []) {
            $meta['navigation_overrides'] = $overrides;
        }
        return $meta;
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public static function apply(array $definition, ?object $current): array
    {
        if ($current === null) {
            return $definition;
        }
        $currentMeta = json_decode($current->meta_json ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $overrides = $currentMeta['navigation_overrides'] ?? [];
        if ($overrides === []) {
            return $definition;
        }
        $meta = json_decode($definition['meta_json'], true, 512, JSON_THROW_ON_ERROR);
        $meta['navigation_overrides'] = $overrides;
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $overrides)) {
                $definition[$column] = $overrides[$column];
            }
        }
        if ($definition['type'] === 'external' && array_key_exists('external_url', $overrides)) {
            $meta['external_url'] = $overrides['external_url'];
        }
        $definition['meta_json'] = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $definition;
    }
}
