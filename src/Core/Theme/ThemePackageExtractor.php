<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ThemePackageExtractor
{
    public function extract(string $source, string $destination): string
    {
        $sourcePath = realpath($source);
        if ($sourcePath === false) {
            throw new ThemeException('Theme package source is unavailable.', 'theme_package_unavailable');
        }
        $this->ensureDirectory($destination);
        if (is_dir($sourcePath)) {
            $this->copyDirectory($sourcePath, $destination);
            return $destination;
        }
        if (!class_exists(ZipArchive::class)) {
            throw new ThemeException('ZIP extraction is unavailable on this installation.', 'zip_unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new ThemeException('Theme archive is invalid.', 'invalid_theme_archive');
        }
        try {
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                $normalized = is_string($entry) ? rtrim($entry, '/') : '';
                if (!is_string($entry) || !$this->safePath($normalized) || isset($seen[$normalized])) {
                    throw new ThemeException('Theme archive contains an unsafe path.', 'unsafe_theme_path');
                }
                $seen[$normalized] = true;
                $system = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX) {
                    $type = ($attributes >> 16) & 0170000;
                    if (!in_array($type, [0, 0040000, 0100000], true)) {
                        throw new ThemeException('Theme archive contains a link or special file.', 'unsafe_theme_path');
                    }
                }
                if (str_ends_with($entry, '/')) {
                    $this->ensureDirectory($destination.'/'.rtrim($entry, '/'));
                    continue;
                }
                $target = $destination.'/'.$entry;
                $this->ensureDirectory(dirname($target));
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new ThemeException('Theme archive entry cannot be read.', 'invalid_theme_archive');
                }
                $contents = stream_get_contents($stream);
                fclose($stream);
                if ($contents === false || file_put_contents($target, $contents) === false) {
                    throw new RuntimeException('Theme archive entry cannot be extracted.');
                }
            }
        } finally {
            $zip->close();
        }

        return $destination;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($source))), '/');
            if (!$this->safePath($relative) || $item->isLink()) {
                throw new ThemeException('Theme package contains an unsafe path.', 'unsafe_theme_path');
            }
            $target = $destination.'/'.$relative;
            if ($item->isDir()) {
                $this->ensureDirectory($target);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Theme package file cannot be staged.');
            }
        }
    }

    private function safePath(string $path): bool
    {
        $segments = explode('/', $path);
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('', $segments, true) && !in_array('.', $segments, true) && !in_array('..', $segments, true);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Theme staging directory cannot be created: '.$directory);
        }
    }
}
