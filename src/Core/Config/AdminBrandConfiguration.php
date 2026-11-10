<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

/** 后台部署品牌的公开投影；不读取站点设置，也不公开任意配置键。 */
final class AdminBrandConfiguration
{
    /** @return array{name: string, logo_url: string, login_title: string, login_intro: string, login_slogan: string, logos: array<string, array{url: string, width: int, height: int}>} */
    public function publicValues(): array
    {
        $name = trim((string) config('ptadmin.brand.name', 'PTAdmin'));
        $sharedLogo = trim((string) config('ptadmin.brand.logo_url', ''));
        $title = trim((string) config('ptadmin.brand.login_title', '登录管理后台'));
        $logos = [];
        foreach (['sidebar' => [40, 40], 'login_panel' => [110, 64], 'login_art' => [250, 160]] as $type => [$defaultWidth, $defaultHeight]) {
            $url = trim((string) config('ptadmin.brand.logos.'.$type.'.url', ''));
            $width = (int) config('ptadmin.brand.logos.'.$type.'.width', $defaultWidth);
            $height = (int) config('ptadmin.brand.logos.'.$type.'.height', $defaultHeight);
            $logos[$type] = [
                'url' => $url !== '' ? asset($url) : ($sharedLogo !== '' ? asset($sharedLogo) : ''),
                'width' => $width > 0 ? $width : $defaultWidth,
                'height' => $height > 0 ? $height : $defaultHeight,
            ];
        }

        return [
            'name' => $name !== '' ? $name : 'PTAdmin',
            'logo_url' => $sharedLogo !== '' ? asset($sharedLogo) : '',
            'login_title' => $title !== '' ? $title : '登录管理后台',
            'login_intro' => (string) config('ptadmin.brand.login_intro', '基于 Laravel 的后台管理与应用扩展平台。'),
            'login_slogan' => (string) config('ptadmin.brand.login_slogan', '让应用开发更简单。'),
            'logos' => $logos,
        ];
    }
}
