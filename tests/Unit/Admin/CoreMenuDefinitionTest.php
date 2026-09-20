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
        ]];
        yield '系统配置' => ['admin.config', [
            'type' => 'page',
            'parent_key' => 'admin',
            'page_key' => 'settings.center',
            'path' => '/settings',
            'is_menu' => true,
        ]];
        yield '数据字典' => ['admin.dictionaries', [
            'type' => 'page',
            'parent_key' => 'admin',
            'page_key' => 'dictionary.center',
            'path' => '/admin/dictionaries',
            'is_menu' => true,
        ]];
        yield '主题管理' => ['themes', [
            'type' => 'page',
            'page_key' => 'theme.center',
            'path' => '/themes',
            'is_menu' => true,
        ]];
    }
}
