<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Navigation;

/** 仅向插件自身的可见菜单附加由发布器验证过的图标地址。 */
final class MenuIconResolver
{
    public function resolve(array $menus, array $plugins): array
    {
        $icons = [];
        foreach ($plugins as $plugin) {
            if (($plugin['enabled'] ?? false) && ($plugin['owner_type'] ?? null) === 'addon') {
                $icons[$plugin['code']] = $plugin['icons'] ?? [];
            }
        }
        return $this->decorate($menus, $icons);
    }

    private function decorate(array $menus, array $icons): array
    {
        foreach ($menus as &$menu) {
            unset($menu['icon_url']);
            $code = $menu['owner_code'] ?? '';
            $icon = $menu['icon'] ?? '';
            if (($menu['owner_type'] ?? null) === 'addon' && str_starts_with($icon, $code.':') && isset($icons[$code][$icon])) {
                $menu['icon_url'] = $icons[$code][$icon];
            }
            if (isset($menu['children'])) {
                $menu['children'] = $this->decorate($menu['children'], $icons);
            }
        }
        return $menus;
    }
}
