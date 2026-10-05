<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use JsonException;

final class ThemeManifestValidator
{
    public function __construct(private string $backendVersion = '0.1.0', private ?ThemeDataPackValidator $dataPackValidator = null)
    {
        $this->dataPackValidator ??= new ThemeDataPackValidator();
    }

    /** 市场入库可关闭当前宿主版本匹配；主题身份、约束语法及数据包检查始终保留。 */
    public function validateDirectory(string $directory, string $expectedCode, string $expectedVersion, bool $isDefault = false, bool $checkBackendCompatibility = true): ThemeDescriptor
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new ThemeException('Theme directory is unavailable: '.$directory, 'theme_unavailable', ['directory' => $directory]);
        }
        if ($this->containsSymlink($root)) {
            throw new ThemeException('Theme directory contains a symbolic link: '.$root, 'unsafe_theme_path', ['directory' => $root]);
        }

        $manifestPath = $root.'/theme.json';
        $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if ($raw === false) {
            throw new ThemeException('Theme manifest is unavailable: '.$root, 'theme_manifest_missing', ['directory' => $root]);
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException('Theme manifest is invalid: '.$root, 'invalid_theme_manifest', ['directory' => $root], $exception);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new ThemeException('Theme manifest must be a JSON object: '.$root, 'invalid_theme_manifest', ['directory' => $root]);
        }

        $code = (string) ($manifest['code'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new ThemeException('Theme code is invalid: '.$code, 'invalid_theme_code', ['directory' => $root]);
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new ThemeException('Theme version is invalid: '.$version, 'invalid_theme_version', ['directory' => $root]);
        }
        if ($code !== $expectedCode || $version !== $expectedVersion) {
            throw new ThemeException('Theme manifest does not match its directory: '.$root, 'theme_identity_mismatch', [
                'expected_code' => $expectedCode,
                'expected_version' => $expectedVersion,
                'actual_code' => $code,
                'actual_version' => $version,
            ]);
        }

        $compatibility = $manifest['compatibility'] ?? null;
        if (is_string($compatibility) && $compatibility !== '') {
            try {
                (new VersionParser())->parseConstraints($compatibility);
                $compatible = !$checkBackendCompatibility || Semver::satisfies($this->backendVersion, $compatibility);
            } catch (\UnexpectedValueException $exception) {
                throw new ThemeException('Theme compatibility constraint is invalid: '.$root, 'invalid_theme_constraint', ['constraint' => $compatibility], $exception);
            }
            if (!$compatible) {
                throw new ThemeException('Theme is incompatible with this backend: '.$root, 'theme_incompatible', ['constraint' => $compatibility]);
            }
        }

        if (!is_string($manifest['name'] ?? null) || $manifest['name'] === '') {
            throw new ThemeException('Theme name is required: '.$root, 'invalid_theme_manifest', ['directory' => $root]);
        }
        if (isset($manifest['modules']) && !is_array($manifest['modules'])) {
            throw new ThemeException('Theme modules must be an object: '.$root, 'invalid_theme_manifest', ['directory' => $root]);
        }
        $this->dataPackValidator->validate($manifest['data_packs'] ?? null, $root);

        return new ThemeDescriptor($code, $version, $root, $manifest, $isDefault);
    }

    private function containsSymlink(string $root): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        } catch (\UnexpectedValueException $exception) {
            throw new ThemeException('Theme directory cannot be read: '.$root, 'theme_unreadable', ['directory' => $root], $exception);
        }
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return true;
            }
        }

        return false;
    }
}
