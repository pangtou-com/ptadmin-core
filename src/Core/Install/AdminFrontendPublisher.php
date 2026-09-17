<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * 首次安装时把核心包内的已校验前端原子发布到随机后台目录。
 * 已存在且有效的运行时前端会被保留，插件 modules 目录不会被覆盖。
 */
final class AdminFrontendPublisher
{
    public function __construct(
        private string $source,
        private string $publicRoot,
        private AdminFrontendManifest $manifest
    ) {
    }

    /** @return array{published:bool, frontend_version:string, path:string} */
    public function publishForInstall(string $webPrefix): array
    {
        if (!WebPrefixGenerator::isValid($webPrefix)) {
            throw new RuntimeException('The PTAdmin web prefix is invalid.');
        }

        $target = rtrim($this->publicRoot, '/').'/'.$webPrefix;
        if (is_dir($target)) {
            $current = $this->manifest->verify($target);
            $this->ensureModulesDirectory($target);

            return [
                'published' => false,
                'frontend_version' => (string) $current['frontend_version'],
                'path' => $target,
            ];
        }
        if (file_exists($target)) {
            throw new RuntimeException('The PTAdmin frontend target is not a directory.');
        }

        $sourceManifest = $this->manifest->verify($this->source);
        if (!is_dir($this->publicRoot) && !mkdir($this->publicRoot, 0755, true) && !is_dir($this->publicRoot)) {
            throw new RuntimeException('Unable to create the PTAdmin public directory.');
        }
        $targetParent = dirname($target);
        if (!is_dir($targetParent) && !mkdir($targetParent, 0755, true) && !is_dir($targetParent)) {
            throw new RuntimeException('Unable to create the PTAdmin frontend target directory.');
        }

        $staging = rtrim($this->publicRoot, '/').'/.ptadmin-'.str_replace('/', '-', $webPrefix).'-'.bin2hex(random_bytes(6));
        if (!mkdir($staging, 0755, true)) {
            throw new RuntimeException('Unable to create the PTAdmin frontend staging directory.');
        }

        try {
            foreach ($sourceManifest['files'] as $file) {
                $path = (string) $file['path'];
                $destination = $staging.'/'.$path;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException(sprintf('Unable to create frontend directory: %s', $path));
                }
                if (!copy($this->source.'/'.$path, $destination)) {
                    throw new RuntimeException(sprintf('Unable to publish frontend file: %s', $path));
                }
            }
            if (!copy(
                $this->source.'/ptadmin-frontend-manifest.json',
                $staging.'/ptadmin-frontend-manifest.json'
            )) {
                throw new RuntimeException('Unable to publish the PTAdmin frontend manifest.');
            }
            $this->ensureModulesDirectory($staging);
            $this->manifest->verify($staging);
            if (!rename($staging, $target)) {
                throw new RuntimeException('Unable to publish the PTAdmin frontend directory.');
            }
        } finally {
            if (is_dir($staging)) {
                $this->deleteDirectory($staging);
            }
        }

        return [
            'published' => true,
            'frontend_version' => (string) $sourceManifest['frontend_version'],
            'path' => $target,
        ];
    }

    /**
     * Replace the current core frontend with a verified artifact. The existing
     * modules directory is moved into the staged tree before the directory swap.
     * @return array{published:bool, frontend_version:string, path:string}
     */
    public function publishForUpdate(string $webPrefix, ?string $source = null): array
    {
        if (!WebPrefixGenerator::isValid($webPrefix)) {
            throw new RuntimeException('The PTAdmin web prefix is invalid.');
        }
        $source ??= $this->source;
        $sourceManifest = $this->manifest->verify($source);
        $target = rtrim($this->publicRoot, '/').'/'.$webPrefix;
        if (!is_dir($target)) {
            throw new RuntimeException('The PTAdmin frontend target is unavailable.');
        }
        $this->manifest->verify($target);
        $staging = rtrim($this->publicRoot, '/').'/.ptadmin-update-'.bin2hex(random_bytes(6));
        $backup = rtrim($this->publicRoot, '/').'/.ptadmin-backup-'.bin2hex(random_bytes(6));
        if (!mkdir($staging, 0755, true)) {
            throw new RuntimeException('Unable to create the PTAdmin frontend staging directory.');
        }
        $swapped = false;
        try {
            foreach ($sourceManifest['files'] as $file) {
                $path = (string) $file['path'];
                $destination = $staging.'/'.$path;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException(sprintf('Unable to create frontend directory: %s', $path));
                }
                if (!copy($source.'/'.$path, $destination)) {
                    throw new RuntimeException(sprintf('Unable to stage frontend file: %s', $path));
                }
            }
            if (!copy($source.'/ptadmin-frontend-manifest.json', $staging.'/ptadmin-frontend-manifest.json')) {
                throw new RuntimeException('Unable to stage the PTAdmin frontend manifest.');
            }
            $modules = $target.'/modules';
            if (is_dir($modules) && !rename($modules, $staging.'/modules')) {
                throw new RuntimeException('Unable to preserve PTAdmin frontend modules.');
            }
            $this->ensureModulesDirectory($staging);
            $this->manifest->verify($staging);
            if (!rename($target, $backup)) {
                throw new RuntimeException('Unable to stage the current PTAdmin frontend.');
            }
            if (!rename($staging, $target)) {
                rename($backup, $target);
                throw new RuntimeException('Unable to activate the PTAdmin frontend update.');
            }
            $swapped = true;
            $this->deleteDirectory($backup);
        } finally {
            if (is_dir($backup) && !$swapped && !is_dir($target)) {
                @rename($backup, $target);
            }
            if (!$swapped && is_dir($staging.'/modules') && !is_dir($target.'/modules')) {
                @rename($staging.'/modules', $target.'/modules');
            }
            if (is_dir($staging)) {
                $this->deleteDirectory($staging);
            }
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
        }

        return [
            'published' => true,
            'frontend_version' => (string) $sourceManifest['frontend_version'],
            'path' => $target,
        ];
    }

    private function ensureModulesDirectory(string $root): void
    {
        $modules = $root.'/modules';
        if (is_link($modules) || (file_exists($modules) && !is_dir($modules))) {
            throw new RuntimeException('The PTAdmin frontend modules path is invalid.');
        }
        if (!is_dir($modules) && !mkdir($modules, 0755, true) && !is_dir($modules)) {
            throw new RuntimeException('Unable to create the PTAdmin frontend modules directory.');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
