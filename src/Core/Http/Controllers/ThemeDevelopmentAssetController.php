<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Routing\Controller;
use PTAdmin\Core\Theme\ActiveThemeResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;

final class ThemeDevelopmentAssetController extends Controller
{
    public function __invoke(string $identifier, string $path, ActiveThemeResolver $themes): BinaryFileResponse
    {
        $development = (array) config('ptadmin.site_theme.development_assets', []);
        if (($development['enabled'] ?? false) !== true) {
            abort(404);
        }

        $theme = $themes->active();
        $expected = ($development['code'] ?? '').'@'.($development['version'] ?? '');
        if ($theme === null || $identifier !== $expected || $theme->identifier() !== $identifier) {
            abort(404);
        }

        if (!str_starts_with($path, 'assets/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            abort(404);
        }
        $path = substr($path, strlen('assets/'));
        if ($path === '') {
            abort(404);
        }
        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            abort(404);
        }
        $assetsRoot = realpath($theme->directory.'/assets');
        $asset = $theme->directory.'/assets/'.$path;
        $real = realpath($asset);
        if ($assetsRoot === false || $real === false || !is_file($real) || is_link($asset)
            || !str_starts_with($real, $assetsRoot.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $mime = $this->mimeType($real);
        
        $response = response()->file($real);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function mimeType(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'txt' => 'text/plain; charset=UTF-8',
        ];

        return $types[$extension] ?? MimeTypes::getDefault()->guessMimeType($path) ?? 'application/octet-stream';
    }
}
