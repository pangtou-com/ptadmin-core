<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use InvalidArgumentException;

final class ThemeAssetResolver
{
    public function __construct(private ActiveThemeResolver $themes)
    {
    }

    public function url(string $path): string
    {
        $theme = $this->themes->active();
        if ($theme === null) {
            throw new ThemeException('No active site theme is available.', 'theme_unavailable');
        }
        $normalized = $this->normalizePath($path);
        $asset = $theme->directory.'/assets/'.$normalized;
        $real = realpath($asset);
        $assetsRoot = realpath($theme->directory.'/assets');
        if ($real === false || $assetsRoot === false || !is_file($real) || !str_starts_with($real, $assetsRoot.DIRECTORY_SEPARATOR)) {
            throw new ThemeException('Theme asset is unavailable: '.$normalized, 'theme_asset_unavailable', ['path' => $normalized, 'theme' => $theme->identifier()]);
        }
        if (is_link($asset)) {
            throw new ThemeException('Theme asset cannot be a symbolic link: '.$normalized, 'unsafe_theme_path', ['path' => $normalized]);
        }

        return '/themes/'.$theme->identifier().'/assets/'.$normalized;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Theme asset path must be relative to the assets directory.');
        }
        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Theme asset path contains an invalid segment.');
        }

        return implode('/', $segments);
    }
}
