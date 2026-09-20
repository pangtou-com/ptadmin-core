<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AddonFrontendPublisher
{
    public function __construct(private string $publicRoot)
    {
    }

    /** @return array{published:bool, code:string, path:string, files:int} */
    public function publish(string $webPrefix, string $code, string $source): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{5,63}$/', $webPrefix) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon frontend target is invalid.', 'invalid_target');
        }
        $sourceRoot = realpath($source);
        if (false === $sourceRoot || !is_dir($sourceRoot)) {
            throw new AddonManifestException('The addon frontend source is unavailable.', 'source_unavailable');
        }
        $base = rtrim($this->publicRoot, '/').'/'.$webPrefix.'/modules';
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create addon frontend modules directory.');
        }
        $target = $base.'/'.$code;
        $staging = $base.'/.'.$code.'.staging-'.bin2hex(random_bytes(5));
        $backup = $base.'/.'.$code.'.backup-'.bin2hex(random_bytes(5));
        mkdir($staging, 0755, true);
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    throw new AddonManifestException('The addon frontend contains a symlink or invalid entry.', 'unsafe_path');
                }
                $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sourceRoot))), '/');
                if (!$this->safePath($relative)) {
                    throw new AddonManifestException('The addon frontend path is invalid.', 'unsafe_path');
                }
                $destination = $staging.'/'.$relative;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create addon frontend directory.');
                }
                if (!copy($file->getPathname(), $destination)) {
                    throw new RuntimeException('Unable to stage addon frontend file.');
                }
                $count++;
            }
            if (is_dir($target) && !rename($target, $backup)) {
                throw new RuntimeException('Unable to stage current addon frontend.');
            }
            if (!rename($staging, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new RuntimeException('Unable to activate addon frontend.');
            }
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
        } finally {
            if (is_dir($staging)) {
                $this->deleteDirectory($staging);
            }
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
        }
        return ['published' => true, 'code' => $code, 'path' => $target, 'files' => $count];
    }

    public function remove(string $webPrefix, string $code): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{5,63}$/', $webPrefix) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon frontend target is invalid.', 'invalid_target');
        }
        $target = rtrim($this->publicRoot, '/').'/'.$webPrefix.'/modules/'.$code;
        if (is_dir($target)) {
            $this->deleteDirectory($target);
        }
    }

    private function safePath(string $path): bool
    {
        $segments = explode('/', $path);
        return '' !== $path && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('..', $segments, true) && !in_array('.', $segments, true) && !in_array('', $segments, true);
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
