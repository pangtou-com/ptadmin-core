<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin\Navigation;

use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Navigation\MenuIconResolver;

final class MenuIconResolverTest extends TestCase
{
    public function testIconsAreScopedToVisibleOwnerMenusAndReplacedWithEachSession(): void
    {
        $menus = [[
            'key' => 'cms', 'owner_type' => 'addon', 'owner_code' => 'cms', 'icon' => 'cms:article',
            'children' => [
                ['key' => 'foreign', 'owner_type' => 'addon', 'owner_code' => 'other', 'icon' => 'cms:article'],
                ['key' => 'builtin', 'owner_type' => 'addon', 'owner_code' => 'cms', 'icon' => 'file-text'],
            ],
        ]];
        $plugins = [['code' => 'cms', 'owner_type' => 'addon', 'enabled' => true, 'entries' => [], 'icons' => [
            'cms:article' => './plugins/cms/icons/article.svg?v=hash',
        ]]];
        $resolver = new MenuIconResolver();
        $resolved = $resolver->resolve($menus, $plugins);
        self::assertSame('./plugins/cms/icons/article.svg?v=hash', $resolved[0]['icon_url']);
        self::assertArrayNotHasKey('icon_url', $resolved[0]['children'][0]);
        self::assertArrayNotHasKey('icon_url', $resolved[0]['children'][1]);
        self::assertSame($menus, $resolver->resolve($resolved, []));
        $plugins[0]['enabled'] = false;
        self::assertSame($menus, $resolver->resolve($resolved, $plugins));
    }
}
