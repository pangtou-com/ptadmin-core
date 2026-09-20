<?php

declare(strict_types=1);

namespace PTAdmin\Schema\SchemaFile;

use PTAdmin\Addon\Catalog\AddonCatalog;
use RuntimeException;

final class SchemaSourceDiscovery
{
    public function __construct(
        private AddonCatalog $addons,
        private string $applicationPath,
        private string $corePath,
        private string $addonsPath,
        private string $applicationOwner = 'host',
    ) {
        $this->applicationPath = rtrim($applicationPath, '/');
        $this->corePath = rtrim($corePath, '/');
        $this->addonsPath = rtrim($addonsPath, '/');
    }

    /**
     * @return list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}>
     */
    public function discover(): array
    {
        $sources = [];
        $this->appendDirectory($sources, $this->corePath, 'core', 'ptadmin', 'core');
        $this->appendDirectory($sources, $this->applicationPath, 'app', $this->applicationOwner, 'application');

        $scan = $this->addons->scan(false);
        if ($scan['errors'] !== []) {
            $diagnostics = array_map(
                static fn (array $error): string => (string) ($error['code'] ?? 'unknown').': '.(string) ($error['message'] ?? 'discovery failed'),
                $scan['errors']
            );
            throw new RuntimeException('Unable to discover addon Schema sources: '.implode('; ', $diagnostics));
        }
        foreach ($scan['addons'] as $addon) {
            if (($addon['state'] ?? '') !== 'active') {
                continue;
            }
            $code = (string) ($addon['code'] ?? '');
            $this->appendDirectory(
                $sources,
                $this->addonsPath.'/'.$code.'/database/schemas',
                'addon',
                strtolower($code),
                'addon:'.$code
            );
        }

        usort($sources, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $sources;
    }

    /**
     * @return list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}>
     */
    public function discoverCore(): array
    {
        return $this->discoverDirectory($this->corePath, 'core', 'ptadmin', 'core');
    }

    /**
     * @return list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}>
     */
    public function discoverDirectory(
        string $directory,
        string $ownerType,
        string $ownerCode,
        string $source
    ): array {
        $sources = [];
        $this->appendDirectory($sources, rtrim($directory, '/'), $ownerType, $ownerCode, $source);
        usort($sources, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $sources;
    }

    /**
     * @param list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}> $sources
     */
    private function appendDirectory(
        array &$sources,
        string $directory,
        string $ownerType,
        string $ownerCode,
        string $source
    ): void {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!preg_match('/\.schema\.jsonc?$/i', $path)) {
                continue;
            }
            $sources[] = [
                'path' => $path,
                'relative_path' => ltrim(substr($path, strlen($directory)), '/'),
                'owner_type' => $ownerType,
                'owner_code' => $ownerCode,
                'source' => $source,
            ];
        }
    }
}
