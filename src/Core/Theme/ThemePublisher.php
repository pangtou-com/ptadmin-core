<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ThemePublisher
{
    private const COVER_EXTENSIONS = ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

    public function __construct(private string $publicRoot)
    {
        $this->publicRoot = rtrim($publicRoot, '/');
    }

    /** @return array{published:bool,path:string,files:int,cover_url:?string} */
    public function publish(ThemeDescriptor $theme): array
    {
        $base = $this->publicRoot;
        $this->ensureDirectory($base);
        $target = $base.'/'.$theme->identifier();
        if (is_dir($target) || is_file($target)) {
            throw new ThemeException('Theme public assets already exist: '.$theme->identifier(), 'theme_already_published', ['theme' => $theme->identifier()]);
        }
        $staging = $base.'/.'.$theme->identifier().'.staging-'.bin2hex(random_bytes(5));
        $this->ensureDirectory($staging);
        $files = 0;
        $cover = $this->findCover($theme->directory);
        try {
            if ($cover !== null) {
                copy($cover['path'], $staging.'/'.$cover['name']);
                $files++;
            }
            $assets = $theme->directory.'/assets';
            if (is_dir($assets)) {
                $files += $this->copyAssets($assets, $staging.'/assets');
            }
            if (!rename($staging, $target)) {
                throw new RuntimeException('Theme public assets cannot be activated.');
            }
        } finally {
            if (is_dir($staging)) {
                $this->deleteDirectory($staging);
            }
        }

        return [
            'published' => true,
            'path' => $target,
            'files' => $files,
            'cover_url' => $cover === null ? null : '/themes/'.$theme->identifier().'/'.$cover['name'],
        ];
    }

    public function targetPath(string $identifier): string
    {
        return $this->publicRoot.'/'.$identifier;
    }

    public function isPublished(string $identifier): bool
    {
        return is_dir($this->targetPath($identifier));
    }

    public function remove(string $identifier): void
    {
        $target = $this->targetPath($identifier);
        if (is_dir($target)) {
            $this->deleteDirectory($target);
        } elseif (is_file($target) || is_link($target)) {
            throw new ThemeException('Theme public asset target is unsafe: '.$identifier, 'unsafe_theme_path');
        }
    }

    private function findCover(string $directory): ?array
    {
        foreach (self::COVER_EXTENSIONS as $extension) {
            $path = $directory.'/cover.'.$extension;
            if (is_file($path) && !is_link($path)) {
                return ['path' => $path, 'name' => 'cover.'.$extension];
            }
        }

        return null;
    }

    private function copyAssets(string $source, string $destination): int
    {
        $this->ensureDirectory($destination);
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new ThemeException('Theme assets contain a symlink or invalid entry.', 'unsafe_theme_path');
            }
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source))), '/');
            if (!$this->safePublicPath($relative)) {
                throw new ThemeException('Theme asset path is invalid: '.$relative, 'unsafe_theme_path');
            }
            $target = $destination.'/'.$relative;
            $this->ensureDirectory(dirname($target));
            if (!copy($file->getPathname(), $target)) {
                throw new RuntimeException('Theme asset cannot be staged: '.$relative);
            }
            $count++;
        }

        return $count;
    }

    private function safePublicPath(string $path): bool
    {
        $segments = explode('/', $path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('', $segments, true) && !in_array('.', $segments, true) && !in_array('..', $segments, true)
            && !in_array($extension, ['env', 'phar', 'php', 'phtml'], true);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Theme public directory cannot be created: '.$directory);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
