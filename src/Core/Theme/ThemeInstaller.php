<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use JsonException;
use RuntimeException;

final class ThemeInstaller
{
    public function __construct(
        private ThemePackageExtractor $extractor,
        private ThemeManifestValidator $validator,
        private string $templatesDirectory,
        private string $stagingDirectory,
    ) {
        $this->templatesDirectory = rtrim($templatesDirectory, '/');
        $this->stagingDirectory = rtrim($stagingDirectory, '/');
    }

    /** @return array<string,mixed> */
    public function install(string $source, ?string $expectedCode = null, ?string $expectedVersion = null): array
    {
        $stage = $this->stagingDirectory.'/'.bin2hex(random_bytes(8));
        $package = $stage.'/package';
        $target = null;
        $installed = false;
        $this->ensureDirectory($stage);
        try {
            $this->extractor->extract($source, $package);
            if (is_file($package.'/ptadmin-source.json')) {
                throw new ThemeException('源码包用于二次开发，请选择安装包进行安装。', 'source_package_not_installable');
            }
            [$code, $version] = $this->manifestIdentity($package);
            if ($expectedCode !== null && strcasecmp($expectedCode, $code) !== 0) {
                throw new ThemeException('Theme code does not match the requested operation.', 'theme_code_mismatch');
            }
            if ($expectedVersion !== null && $expectedVersion !== $version) {
                throw new ThemeException('Theme version does not match the requested operation.', 'theme_version_mismatch');
            }
            $theme = $this->validator->validateDirectory($package, $code, $version);
            $this->validatePublicAssets($package.'/assets');
            $target = $this->templatesDirectory.'/'.$theme->identifier();
            if (is_dir($target) || is_file($target)) {
                throw new ThemeException('Theme version is already installed.', 'theme_already_installed', ['theme' => $theme->identifier()]);
            }
            $this->ensureDirectory($this->templatesDirectory);
            if (!rename($package, $target)) {
                throw new RuntimeException('Theme package cannot be activated.');
            }
            $installed = true;
            $theme = $this->validator->validateDirectory($target, $code, $version);

            return [
                'code' => $theme->code,
                'version' => $theme->version,
                'path' => $target,
                'public' => false,
            ];
        } catch (\Throwable $exception) {
            if ($installed && is_string($target) && is_dir($target)) {
                $this->deleteDirectory($target);
            }
            throw $exception;
        } finally {
            if (is_dir($stage)) {
                $this->deleteDirectory($stage);
            }
        }
    }

    /** @return array{0:string,1:string} */
    private function manifestIdentity(string $package): array
    {
        $raw = file_get_contents($package.'/theme.json');
        if ($raw === false) {
            throw new ThemeException('Theme manifest is unavailable.', 'theme_manifest_missing');
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException('Theme manifest is invalid.', 'invalid_theme_manifest', [], $exception);
        }
        if (!is_array($manifest)) {
            throw new ThemeException('Theme manifest must be a JSON object.', 'invalid_theme_manifest');
        }
        return [(string) ($manifest['code'] ?? ''), (string) ($manifest['version'] ?? '')];
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Theme staging directory cannot be created: '.$directory);
        }
    }

    private function validatePublicAssets(string $assets): void
    {
        if (!is_dir($assets)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assets, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new ThemeException('Theme assets contain a symlink or invalid entry.', 'unsafe_theme_path');
            }
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($assets))), '/');
            if (!$this->safePublicPath($relative)) {
                throw new ThemeException('Theme asset path is invalid: '.$relative, 'unsafe_theme_path');
            }
        }
    }

    private function safePublicPath(string $path): bool
    {
        $segments = explode('/', $path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('', $segments, true) && !in_array('.', $segments, true) && !in_array('..', $segments, true)
            && !in_array($extension, ['env', 'phar', 'php', 'phtml'], true);
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
