<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Navigation;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Schema\Contract\SchemaError;

/** 外链没有业务入口时提供独立的查看权限，登记资源不产生授权。 */
final class ExternalNavigationPermission
{
    public static function entry(string $type, string $key, string $configured): string
    {
        return $type === 'external' && $configured === '' ? $key.'.view' : $configured;
    }

    /** @param array<string,mixed> $menu @param array<string,mixed> $meta @return array<string,mixed> */
    public static function definition(array $menu, array $meta): array
    {
        return [
            'resource_key' => $menu['resource_key'], 'title' => $menu['title'], 'type' => 'permission',
            'module' => $menu['module'] ?? 'admin', 'parent_key' => '', 'path' => '', 'is_menu' => false,
            'status' => true, 'sort' => $menu['sort'] ?? 0, 'deleted_at' => null,
            'meta_json' => json_encode([
                'owner_type' => $meta['owner_type'] ?? 'unknown', 'owner_code' => $meta['owner_code'] ?? '',
                'navigation_key' => $menu['resource_key'], 'abilities' => ['view'], 'scopes' => ['all'],
                'operations' => ['view' => ['title' => '查看', 'desc' => '查看此外部链接导航入口，不授予目标网站权限。']],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array<string,mixed> $definition */
    public static function assertOwnership(array $definition, ?object $existing): void
    {
        if ($existing === null) {
            return;
        }
        $meta = json_decode($definition['meta_json'], true, 512, JSON_THROW_ON_ERROR);
        $previous = json_decode($existing->meta_json ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if ($existing->deleted_at !== null || $existing->type !== 'permission'
            || ($previous['navigation_key'] ?? null) !== $definition['resource_key']
            || ($previous['owner_type'] ?? '') !== $meta['owner_type']
            || ($previous['owner_code'] ?? '') !== $meta['owner_code']) {
            throw new SchemaError('navigation_permission_conflict', '外链导航编码已被其他权限资源使用，请选择已有入口业务权限。', [
                'resource_key' => $definition['resource_key'],
            ]);
        }
    }

    /** 在导航写入事务或升级迁移中登记，已存在权限的状态和授权保持不变。
     * @param array<string,mixed> $menu @param array<string,mixed> $meta
     */
    public static function register(ConnectionInterface $database, array $menu, array $meta): void
    {
        $definition = self::definition($menu, $meta);
        $existing = $database->table('admin_resources')->where('resource_key', $definition['resource_key'])->first();
        self::assertOwnership($definition, $existing);
        if ($existing === null) {
            $database->table('admin_resources')->insert($definition + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
