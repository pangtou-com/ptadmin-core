<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use FilesystemIterator;
use PTAdmin\Addon\Exception\AddonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * 将已校验的插件前端产物发布到后台公开 plugins 目录。
 *
 * 发布使用 staging 和 backup 目录完成原子替换，避免复制中断时让请求读取到不完整模块；该服务不负责
 * manifest 信任校验，调用方必须先完成插件包验证。
 */
final class FrontendPublisher
{
    public function __construct(private string $publicRoot)
    {
    }

    /**
     * 发布一个插件的完整前端目录，并在成功切换后删除上一版本。
     *
     * @return array{published:bool, code:string, path:string, files:int}
     */
    public function publish(string $webPrefix, string $code, string $source): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{5,63}$/', $webPrefix) !== 1 || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon frontend target is invalid.', 'invalid_target');
        }
        $sourceRoot = realpath($source);
        if (false === $sourceRoot || !is_dir($sourceRoot)) {
            throw new AddonException('The addon frontend source is unavailable.', 'source_unavailable');
        }
        $base = rtrim($this->publicRoot, '/').'/'.$webPrefix.'/plugins';
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create addon frontend plugins directory.');
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
                    throw new AddonException('The addon frontend contains a symlink or invalid entry.', 'unsafe_path');
                }
                $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sourceRoot))), '/');
                if (!$this->safePath($relative)) {
                    throw new AddonException('The addon frontend path is invalid.', 'unsafe_path');
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

    /** 将二次开发应用前端发布到固定 modules/app 目录，不触碰插件产物。 */
    public function publishApplication(string $webPrefix, string $source): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{5,63}$/', $webPrefix) !== 1) {
            throw new AddonException('The application frontend target is invalid.', 'invalid_target');
        }
        $sourceRoot = realpath($source);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new AddonException('The application frontend source is unavailable.', 'source_unavailable');
        }
        $base = rtrim($this->publicRoot, '/').'/'.$webPrefix.'/modules';
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create application frontend modules directory.');
        }
        $target = $base.'/app';
        $staging = $base.'/.app.staging-'.bin2hex(random_bytes(5));
        $backup = $base.'/.app.backup-'.bin2hex(random_bytes(5));
        mkdir($staging, 0755, true);
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    throw new AddonException('The application frontend contains a symlink or invalid entry.', 'unsafe_path');
                }
                $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sourceRoot))), '/');
                if (!$this->safePath($relative)) {
                    throw new AddonException('The application frontend path is invalid.', 'unsafe_path');
                }
                $destination = $staging.'/'.$relative;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create application frontend directory.');
                }
                if (!copy($file->getPathname(), $destination)) {
                    throw new RuntimeException('Unable to stage application frontend file.');
                }
                $count++;
            }
            $files = $this->fileManifest($staging);
            file_put_contents($staging.'/.ptadmin-app-manifest.json', json_encode([
                'manifest_version' => 1,
                'files' => $files,
                'artifact_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (is_dir($target) && !rename($target, $backup)) {
                throw new RuntimeException('Unable to stage current application frontend.');
            }
            if (!rename($staging, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new RuntimeException('Unable to activate application frontend.');
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

        return ['published' => true, 'code' => 'host', 'path' => $target, 'files' => $count];
    }

    /** 仅删除指定插件的公开模块目录，不触碰插件源码、配置或业务数据。 */
    public function remove(string $webPrefix, string $code): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{5,63}$/', $webPrefix) !== 1 || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon frontend target is invalid.', 'invalid_target');
        }
        $target = rtrim($this->publicRoot, '/').'/'.$webPrefix.'/plugins/'.$code;
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

    /** @return list<array{path:string,size:int,sha256:string}> */
    private function fileManifest(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root))), '/');
            if ($relative === '.ptadmin-app-manifest.json') {
                continue;
            }
            $files[] = ['path' => $relative, 'size' => (int) filesize($file->getPathname()), 'sha256' => (string) hash_file('sha256', $file->getPathname())];
        }
        usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $files;
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
