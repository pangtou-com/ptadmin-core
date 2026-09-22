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
    ) {
        $this->templatesDirectory = rtrim($templatesDirectory, '/');
    }

    /** @return array<string,mixed> */
    public function activate(string $code, string $version): array
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
