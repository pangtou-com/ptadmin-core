<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use PTAdmin\Template\Directive\DirectiveRegistration;

final class ThemeDirectiveProvider
{
    public function __construct(private ThemeAssetResolver $assets)
    {
    }

    public function register(DirectiveRegistration $registry): void
    {
        $registry->define('theme_asset')->output([$this, 'themeAsset'])->alias('theme_asset')
            ->description('输出当前站点主题的静态资源地址。')
            ->parameter('path', type: 'string', description: 'assets 目录下的相对路径。', required: true)
            ->commit();
    }

    public function themeAsset(array $arguments, array $context = []): string
    {
        return $this->assets->url((string) $arguments['path']);
    }
}
