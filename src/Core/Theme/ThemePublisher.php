<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ThemePublisher
{
    private const COVER_EXTENSIONS = ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

    public function __construct(private string $publicRoot, private readonly Filesystem $files = new Filesystem())
    {
        $this->publicRoot = rtrim($publicRoot, '/');
    }

    /** @return array{published:bool,path:string,files:int,cover_url:?string} */
    public function publish(ThemeDescriptor $theme): array
    {
        if (is_link($theme->directory) || is_link($theme->directory.'/assets')) {
            throw new ThemeException('主题资源源目录不能是符号链接。', 'unsafe_theme_path');
        }
        $base = $this->publicRoot;
        $this->ensureDirectory($base);
        $target = $base.'/'.$theme->identifier();
        if (is_link($target) || file_exists($target)) {
            throw new ThemeException('Theme public assets already exist: '.$theme->identifier(), 'theme_already_published', ['theme' => $theme->identifier()]);
        }
        $staging = $base.'/.'.$theme->identifier().'.staging-'.bin2hex(random_bytes(5));
        $this->ensureDirectory($staging);
        $files = 0;
        $cover = $this->findCover($theme->directory);
        try {
            if ($cover !== null) {
                if (!copy($cover['path'], $staging.'/'.$cover['name'])) {
                    throw new RuntimeException('无法准备主题封面。');
                }
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

    /**
     * 替换前保留私有备份；旧目录先同盘重命名，切换失败可直接恢复。
     * 调用方须持有主题生命周期锁。
     * @return array{published:bool,path:string,files:int,cover_url:?string,backup_id:?string}
     */
    public function republish(ThemeDescriptor $theme, string $backupDirectory): array
    {
        $this->ensureDirectory($this->publicRoot);
        $target = $this->targetPath($theme->identifier());
        if (is_link($target) || file_exists($target) && !is_dir($target)) {
            throw new ThemeException('主题公开目录不是安全的普通目录。', 'unsafe_theme_path');
        }
        $operation = $theme->identifier().'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6));
        $stageRoot = $this->publicRoot.'/.publish-'.$operation;
        $previous = $this->publicRoot.'/.previous-'.$operation;
        $backupId = null;
        try {
            $staged = (new self($stageRoot, $this->files))->publish($theme);
            if (is_dir($target)) {
                // 备份完整旧目录，包含用户手动放入的文件；非法路径或链接会使发布失败。
                $this->ensureDirectory($backupDirectory, 0700);
                $backupId = $operation;
                $this->copyAssets($target, $backupDirectory.'/'.$backupId);
                if (!$this->files->move($target, $previous)) {
                    throw new ThemeException('无法暂存旧主题资源，原公开目录未替换。', 'theme_publish_failed');
                }
            }
            try {
                if (!$this->files->move($staged['path'], $target)) {
                    throw new ThemeException('主题资源切换失败，已保留原公开目录。', 'theme_publish_failed');
                }
            } catch (\Throwable $error) {
                if (is_dir($previous) && !$this->files->move($previous, $target)) {
                    throw new ThemeException('主题发布和自动恢复均失败，请从备份恢复：'.$backupId, 'theme_restore_failed', [], $error);
                }
                throw $error;
            }
            if (is_dir($previous)) {
                $this->deleteDirectory($previous);
            }
            return array_replace($staged, ['path' => $target, 'backup_id' => $backupId]);
        } finally {
            if (is_dir($stageRoot)) {
                $this->deleteDirectory($stageRoot);
            }
        }
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

    private function ensureDirectory(string $directory, int $mode = 0755): void
    {
        if (is_link($directory)) {
            throw new ThemeException('主题资源目录不能是符号链接。', 'unsafe_theme_path');
        }
        if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
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
