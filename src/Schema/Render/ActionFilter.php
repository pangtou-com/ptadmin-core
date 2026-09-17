<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Render;

/**
 * 根据资源级权限裁剪动作和视图节点，防止前端继续展示未授权的入口。
 */
final class ActionFilter
{
    /**
     * 同步过滤 schema.actions 与页面配置中的 action 节点；restricted 表示资源受限，allowed 表示当前主体获准。
     *
     * @param array<string, mixed> $resource
     * @param array<string, bool> $restricted
     * @param array<string, bool> $allowed
     * @return array<string, mixed>
     */
    public static function filter(array $resource, array $restricted, array $allowed): array
    {
        $actions = (array) ($resource['schema']['actions'] ?? []);
        foreach ($actions as $name => $_) {
            if (!empty($restricted[$name]) && empty($allowed[$name])) {
                unset($actions[$name]);
            }
        }
        $resource['schema']['actions'] = $actions ?: null;
        $resource['schema'] = self::filterViews((array) ($resource['schema'] ?? []), $restricted, $allowed);
        return $resource;
    }

    private static function filterViews(array $schema, array $restricted, array $allowed): array
    {
        foreach (['page', 'pages', 'search', 'searches', 'table', 'tables', 'form', 'forms', 'detail', 'details'] as $key) {
            if (array_key_exists($key, $schema)) {
                $keep = true;
                $schema[$key] = self::walk($schema[$key], $restricted, $allowed, $keep);
                if (!$keep) {
                    $schema[$key] = null;
                }
            }
        }
        return $schema;
    }

    private static function walk(mixed $value, array $restricted, array $allowed, bool &$keep): mixed
    {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if (!$isList && isset($value['action']) && is_string($value['action'])) {
                $action = $value['action'] === 'edit' ? 'update' : $value['action'];
                if (!empty($restricted[$action]) && empty($allowed[$action])) {
                    $keep = false;
                    return null;
                }
            }
            $result = [];
            foreach ($value as $key => $item) {
                $childKeep = true;
                $next = self::walk($item, $restricted, $allowed, $childKeep);
                if ($childKeep) {
                    $result[$key] = $next;
                }
            }
            if (!$isList && ($result['type'] ?? null) === 'dropdown' && empty($result['children'])) {
                $keep = false;
            }
            return $result;
        }
        return $value;
    }
}
