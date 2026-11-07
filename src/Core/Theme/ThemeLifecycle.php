<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ThemeLifecycle
{
    public function __construct(
        private ThemeCatalog $catalog,
        private ActiveThemeResolver $active,
        private ThemeStateStore $state,
        private ThemePublisher $publisher,
        private string $templatesDirectory,
        private string $defaultCode = 'default',
        private string $defaultVersion = '1.0.0',
        private ?ThemeViewLoader $viewLoader = null,
        private ?string $operationsDirectory = null,
    ) {
        $this->templatesDirectory = rtrim($templatesDirectory, '/');
        $this->operationsDirectory ??= $this->templatesDirectory.'/.ptadmin-operations';
    }

    /** @return array<string,mixed> */
    public function activate(string $code, string $version): array
    {
        return $this->exclusive(fn (): array => $this->activateUnlocked($code, $version));
    }

    /** @return array<string,mixed> */
    private function activateUnlocked(string $code, string $version): array
    {
        $theme = $this->catalog->find($code, $version);
        if ($theme === null) {
            throw new ThemeException('Theme is not installed: '.$code.'@'.$version, 'theme_not_found', ['code' => $code, 'version' => $version]);
        }
        $current = $this->active->active();
        $published = false;
        if (($current?->identifier() ?? null) !== $theme->identifier() && $this->publisher->isPublished($theme->identifier())) {
            $this->publisher->remove($theme->identifier());
        }
        if (!$this->publisher->isPublished($theme->identifier())) {
            $this->publisher->publish($theme);
            $published = true;
        }
        try {
            $this->state->write($theme);
        } catch (\Throwable $exception) {
            if ($published) {
                $this->publisher->remove($theme->identifier());
            }
            throw $exception;
        }
        if ($current !== null && $current->identifier() !== $theme->identifier()) {
            $this->publisher->remove($current->identifier());
        }
        $this->active->reset();
        $this->viewLoader?->load($theme);

        return [
            'code' => $theme->code,
            'version' => $theme->version,
            'active' => true,
            'state_file' => true,
            'public' => $this->publisher->targetPath($theme->identifier()),
        ];
    }

    /** @return array<string,mixed> */
    public function remove(string $code, string $version): array
    {
        return $this->exclusive(fn (): array => $this->removeUnlocked($code, $version));
    }

    /** @return array<string,mixed> */
    private function removeUnlocked(string $code, string $version): array
    {
        $theme = $this->catalog->find($code, $version);
        if ($theme === null) {
            throw new ThemeException('Theme is not installed: '.$code.'@'.$version, 'theme_not_found', ['code' => $code, 'version' => $version]);
        }
        $identifier = $theme->identifier();
        if ($identifier === $this->defaultCode.'@'.$this->defaultVersion) {
            throw new ThemeException('The default theme cannot be removed.', 'theme_default_protected', ['theme' => $identifier]);
        }
        $current = $this->active->active();
        if ($current !== null && $current->identifier() === $identifier) {
            throw new ThemeException('The active theme cannot be removed.', 'theme_active_protected', ['theme' => $identifier]);
        }
        $source = $this->templatesDirectory.'/'.$identifier;
        if (is_dir($source)) {
            $this->deleteDirectory($source);
        }
        $this->publisher->remove($identifier);

        return [
            'code' => $code,
            'version' => $version,
            'removed' => true,
            'path' => $source,
        ];
    }

    /** @return array{code:string,version:string,published:bool,files:int,backup_id:?string} */
    public function publishAssets(string $code, string $version): array
    {
        return $this->exclusive(function () use ($code, $version): array {
            $theme = $this->catalog->find($code, $version);
            if ($theme === null) {
                throw new ThemeException('主题不存在或未通过校验：'.$code.'@'.$version, 'theme_not_found');
            }
            if ($this->active->active()?->identifier() !== $theme->identifier()) {
                throw new ThemeException('只能发布当前启用主题的资源，请刷新主题列表。', 'theme_not_active');
            }
            $result = $this->publisher->republish($theme, $this->operationsDirectory.'/backups');
            return ['code' => $code, 'version' => $version, 'published' => true,
                'files' => $result['files'], 'backup_id' => $result['backup_id']];
        });
    }

    /** @param callable():array $operation @return array */
    private function exclusive(callable $operation): array
    {
        $directory = $this->operationsDirectory;
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new ThemeException('无法创建主题操作目录。', 'theme_storage_unavailable');
        }
        $path = $directory.'/lifecycle.lock';
        if (is_link($path)) {
            throw new ThemeException('主题操作锁不能是符号链接。', 'unsafe_theme_path');
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new ThemeException('无法打开主题操作锁。', 'theme_storage_unavailable');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new ThemeException('其他主题操作正在执行，请稍后重试。', 'theme_busy');
            }
            // 长驻进程和页面旧状态不能决定本次操作的当前主题。
            $this->active->reset();
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException('Theme source directory cannot be removed: '.$directory);
        }
    }
}
