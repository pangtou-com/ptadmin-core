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
        $definitions = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/resources/menu.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $menus = array_column($definitions, null, 'resource_key');

        self::assertArrayHasKey($resourceKey, $menus);
        self::assertSame($expected, array_intersect_key($menus[$resourceKey], $expected));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function pageMenuProvider(): iterable
    {
        yield '资源中心' => ['assets', [
            'type' => 'page',
            'page_key' => 'asset.center',
            'path' => '/assets',
            'is_menu' => true,
            'abilities' => ['view', 'upload', 'update', 'delete', 'restore'],
        ]];
        yield '系统配置' => ['admin.config', [
            'type' => 'page',
            'parent_key' => 'admin',
            'page_key' => 'settings.center',
            'path' => '/settings',
            'is_menu' => true,
            'abilities' => ['view', 'design', 'update'],
        ]];
        yield '数据字典' => ['admin.dictionaries', [
            'type' => 'page',
            'parent_key' => 'admin',
            'page_key' => 'dictionary.center',
            'path' => '/admin/dictionaries',
            'is_menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ]];
        yield '主题管理' => ['themes', [
            'type' => 'page',
            'page_key' => 'theme.center',
            'path' => '/themes',
            'is_menu' => true,
            'abilities' => ['view'],
        ]];
    }

    public function testDomainOperationsAreDeclaredAsNonMenuChildResources(): void
    {
        $definitions = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/resources/menu.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $resources = array_column($definitions, null, 'resource_key');

        self::assertSame([
            'type' => 'action',
            'parent_key' => 'themes',
            'is_menu' => false,
            'abilities' => ['read', 'install', 'activate', 'remove'],
        ], array_intersect_key($resources['site.theme'], array_flip([
            'parent_key', 'type', 'is_menu', 'abilities',
        ])));
        self::assertSame('dashboard', $resources['admin.dashboard_widgets']['parent_key']);
        self::assertSame(['update'], $resources['admin.dashboard_widgets']['abilities']);
        self::assertSame('admin.logs', $resources['admin.operation_records']['parent_key']);
        self::assertSame('admin.logs', $resources['admin.login_logs']['parent_key']);
        self::assertSame(false, $resources['core.tasks']['is_menu']);
    }

    public function testSchemaMenuPermissionsAreExplicitlyDeclared(): void
    {
        $definitions = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/resources/menu.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
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
            ['list', 'view'],
            $resources['admin.notifications']['abilities'],
        );
        self::assertSame(
            ['list', 'view', 'create', 'update', 'delete'],
            $resources['admin.notification_templates']['abilities'],
        );
    }
}
