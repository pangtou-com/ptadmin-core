<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreMenuDefinitionTest extends TestCase
{
    /** @param array<string, mixed> $expected */
    #[DataProvider('pageMenuProvider')]
    public function testCorePageIsDeclaredAsVisibleMenu(string $resourceKey, array $expected): void
    {
        $definitions = $this->definitions();
        $menus = array_column($definitions, null, 'resource_key');

        self::assertArrayHasKey($resourceKey, $menus);
        foreach ($expected as $field => $value) {
            self::assertArrayHasKey($field, $menus[$resourceKey]);
            self::assertSame($value, $menus[$resourceKey][$field], $resourceKey.'.'.$field);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function pageMenuProvider(): iterable
    {
        yield '资源中心' => ['assets', [
            'type' => 'page',
            'path' => '/assets',
            'is_menu' => true,
            'abilities' => ['view', 'upload', 'update', 'delete', 'restore'],
        ]];
        yield '系统配置' => ['admin.config', [
            'type' => 'page',
            'parent_key' => 'admin',
            'path' => '/settings',
            'is_menu' => true,
            'abilities' => ['view', 'design', 'update'],
        ]];
        yield '数据字典' => ['admin.dictionaries', [
            'type' => 'page',
            'parent_key' => 'admin',
            'path' => '/admin/dictionaries',
            'is_menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ]];
        yield '主题管理' => ['themes', [
            'type' => 'page',
            'path' => '/themes',
            'is_menu' => true,
            'abilities' => ['view'],
        ]];
    }

    public function testNavigationUsesOneStableKeyAndSupportedTypes(): void
    {
        $definitions = $this->definitions();
        $resources = array_column($definitions, null, 'resource_key');

        self::assertCount(count($definitions), $resources);
        foreach ($resources as $resource) {
            self::assertContains($resource['type'], ['group', 'schema', 'page', 'external', 'action']);
            self::assertArrayNotHasKey('page_key', $resource);
            self::assertArrayNotHasKey('addon_code', $resource);
            if ($resource['type'] === 'page') {
                self::assertNotEmpty($resource['resource_key']);
                self::assertNotEmpty($resource['path']);
                self::assertArrayNotHasKey('target_resource_key', $resource);
            }
        }
    }

    public function testTaskHistoryPermissionsCanBeGrantedWithoutAddingANavigationPage(): void
    {
        $definitions = $this->definitions();
        $resources = array_column($definitions, null, 'resource_key');
        self::assertSame('action', $resources['core.tasks']['type']);
        self::assertFalse($resources['core.tasks']['is_menu']);
        self::assertSame(['view', 'remove'], $resources['core.tasks']['abilities']);
    }

    public function testSchemaMenuPermissionsAreExplicitlyDeclared(): void
    {
        $definitions = $this->definitions();
        $resources = array_column($definitions, null, 'resource_key');

        self::assertSame(
            ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge', 'sync_roles', 'sync_permissions', 'reset_password', 'revoke_sessions'],
            $resources['admin.users']['abilities'],
        );
        self::assertSame(
            ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge', 'sync_permissions'],
            $resources['admin.roles']['abilities'],
        );
        self::assertSame(
            ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge', 'move_resource', 'move_menu'],
            $resources['admin.menus']['abilities'],
        );
        self::assertSame(
            ['list', 'view', 'create', 'update', 'delete'],
            $resources['admin.notification_templates']['abilities'],
        );
    }

    /**
     * @param list<array<string, mixed>>|null $nodes
     * @return list<array<string, mixed>>
     */
    private function definitions(?array $nodes = null, string $parentKey = ''): array
    {
        $nodes ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/resources/menu.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $definitions = [];
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            if ($parentKey !== '') {
                $node['parent_key'] = $parentKey;
            }
            $definitions[] = $node;
            array_push($definitions, ...$this->definitions($children, $node['resource_key']));
        }

        return $definitions;
    }
}
