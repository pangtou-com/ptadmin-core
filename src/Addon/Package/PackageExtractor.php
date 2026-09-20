<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Package;

use PTAdmin\Addon\Exception\AddonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;
use ZipArchive;

/**
 * 将本地插件目录或 ZIP 包展开到任务私有 staging 目录。
 *
 * 解包阶段拒绝路径穿越、重复条目、符号链接和特殊文件；这里只隔离文件系统风险，manifest 文件清单和签名
 * 仍由 ManifestVerifier 在 staging 目录上统一校验。
 */
final class PackageExtractor
{
    /** 返回包含 manifest.json 的 staging 根目录，调用方在验证完成前不得将其切换为正式插件目录。 */
    public function extract(string $source, string $destination): string
    {
        $source = realpath($source) ?: '';
        if ($source === '') {
            throw new AddonException('The addon package source is unavailable.', 'package_unavailable');
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new RuntimeException('Unable to create addon staging directory.');
        }
        if (is_dir($source)) {
            $this->copyDirectory($source, $destination);
            return $destination;
        }
        if (!class_exists(ZipArchive::class)) {
            throw new AddonException('ZIP extraction is unavailable on this installation.', 'zip_unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            throw new AddonException('The addon archive is invalid.', 'invalid_archive');
        }
        try {
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $normalized = is_string($entry) ? rtrim($entry, '/') : '';
                if (!is_string($entry) || !$this->safePath($normalized) || isset($seen[$normalized])) {
                    throw new AddonException('The addon archive contains an unsafe path.', 'unsafe_path');
                }
                $seen[$normalized] = true;
                $operationsSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $operationsSystem, $attributes) && $operationsSystem === ZipArchive::OPSYS_UNIX) {
                    $type = ($attributes >> 16) & 0170000;
                    if (!in_array($type, [0, 0040000, 0100000], true)) {
                        throw new AddonException('The addon archive contains a link or special file.', 'unsafe_path');
                    }
                }
                if (str_ends_with($entry, '/')) {
                    $directory = $destination.'/'.rtrim($entry, '/');
                    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                        throw new RuntimeException('Unable to create addon extraction directory.');
                    }
                    continue;
                }
                $target = $destination.'/'.$entry;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new RuntimeException('Unable to create addon extraction directory.');
                }
                $stream = $zip->getStream($entry);
                if (false === $stream) {
                    throw new AddonException('Unable to read addon archive entry.', 'invalid_archive');
                }
                $contents = stream_get_contents($stream);
                fclose($stream);
                if (false === $contents || false === file_put_contents($target, $contents)) {
                    throw new RuntimeException('Unable to extract addon archive entry.');
                }
            }
        } finally {
            $zip->close();
        }
        return $destination;
    }

    private function safePath(string $path): bool
    {
        $segments = explode('/', $path);
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('..', $segments, true) && !in_array('.', $segments, true) && !in_array('', $segments, true);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($source))), '/');
            if (!$this->safePath($relative) || $item->isLink()) {
                throw new AddonException('The addon package contains an unsafe path.', 'unsafe_path');
            }
            $target = $destination.'/'.$relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                    throw new RuntimeException('Unable to create addon staging directory.');
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Unable to stage addon package file.');
            }
        }
    }
}
