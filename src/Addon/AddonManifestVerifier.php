<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Composer\Semver\Semver;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class AddonManifestVerifier
{
    public function __construct(
        private string $backendVersion = '0.1.0',
        private int $schemaProtocol = 1,
        private ?AddonTrustPolicy $trustPolicy = null
    )
    {
    }

    /** @return array<string, mixed> */
    public function verify(string $directory, bool $requireSignature = true): array
    {
        $root = realpath($directory);
        if (false === $root || !is_dir($root)) {
            throw new AddonManifestException('The addon package directory is unavailable.', 'package_unavailable');
        }
        $manifestPath = $root.'/manifest.json';
        $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if (false === $raw) {
            throw new AddonManifestException('The addon manifest is unavailable.');
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AddonManifestException('The addon manifest is invalid.', 'invalid_addon_manifest');
        }
        if (!is_array($manifest)) {
            throw new AddonManifestException('The addon manifest must be an object.');
        }
        $this->assertHeader($manifest);
        $files = $this->buildFileList($root, $manifest);
        if ($requireSignature) {
            $this->verifySignature($manifest, $files);
            $this->trustPolicy?->assertTrusted($manifest);
        }
        $manifest['verified_files'] = $files;
        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function manifestSha256(array $manifest): string
    {
        $files = is_array($manifest['verified_files'] ?? null) ? $manifest['verified_files'] : [];

        return 'sha256:'.hash('sha256', $this->signingPayload($manifest, $files));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, array<string, mixed>> $files
     */
    public function signingPayload(array $manifest, array $files): string
    {
        if (is_array($manifest['signature'] ?? null)) {
            unset($manifest['signature']['value']);
        }
        unset($manifest['verified_files']);

        return json_encode(
            $this->canonicalize(['manifest' => $manifest, 'files' => $files]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** @param array<string, mixed> $manifest */
    private function assertHeader(array $manifest): void
    {
        $code = (string) ($manifest['code'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1 || '' === $version || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new AddonManifestException('The addon code or version is invalid.');
        }
        if ((int) ($manifest['schema_protocol'] ?? $this->schemaProtocol) !== $this->schemaProtocol) {
            throw new AddonManifestException('The addon schema protocol is incompatible.', 'protocol_incompatible');
        }
        $compatibilityMap = is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [];
        $compatibility = $compatibilityMap['ptadmin/core'] ?? null;
        if (is_string($compatibility) && '' !== trim($compatibility)) {
            try {
                if (!Semver::satisfies($this->backendVersion, $compatibility)) {
                    throw new AddonManifestException('The addon is incompatible with this backend.', 'backend_incompatible');
                }
            } catch (\UnexpectedValueException $exception) {
                throw new AddonManifestException('The addon compatibility constraint is invalid.', 'invalid_constraint');
            }
        }
    }

    /** @param array<string, mixed> $manifest @return array<int, array<string, mixed>> */
    private function buildFileList(string $root, array $manifest): array
    {
        $declared = $manifest['files'] ?? null;
        if (null !== $declared && !is_array($declared)) {
            throw new AddonManifestException('The addon files declaration is invalid.');
        }
        $paths = [];
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new AddonManifestException('The addon package contains a symlink.', 'unsafe_path');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root))), '/');
            if ('manifest.json' !== $relative) {
                $actual[] = $relative;
            }
        }
        sort($actual, SORT_STRING);
        if (is_array($declared)) {
            $seen = [];
            foreach ($declared as $entry) {
                if (!is_string($entry) || !$this->safePath($entry) || 'manifest.json' === $entry || isset($seen[$entry])) {
                    throw new AddonManifestException('The addon file path is invalid.', 'unsafe_path');
                }
                $seen[$entry] = true;
                $paths[] = $entry;
            }
            $declaredSorted = $paths;
            sort($declaredSorted, SORT_STRING);
            if ($declaredSorted !== $actual) {
                throw new AddonManifestException('The addon file list does not match package contents.', 'file_list_mismatch');
            }
        } else {
            $paths = $actual;
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $result = [];
        foreach ($paths as $path) {
            $absolute = $root.'/'.$path;
            $real = realpath($absolute);
            if (false === $real || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || !is_file($real) || $this->containsSymlink($root, $path)) {
                throw new AddonManifestException('The addon file failed path verification: '.$path, 'unsafe_path');
            }
            $result[] = ['path' => $path, 'size' => filesize($real), 'sha256' => hash_file('sha256', $real)];
        }
        return $result;
    }

    /** @param array<string, mixed> $manifest @param array<int, array<string, mixed>> $files */
    private function verifySignature(array $manifest, array $files): void
    {
        $signature = (string) ($manifest['signature']['value'] ?? '');
        $publicKey = (string) ($manifest['signature']['public_key'] ?? '');
        if ('' === $signature || '' === $publicKey) {
            throw new AddonManifestException('The addon signature is required.', 'signature_missing');
        }
        $canonical = $this->signingPayload($manifest, $files);
        $decoded = base64_decode($signature, true);
        if (!is_string($decoded) || 1 !== openssl_verify($canonical, $decoded, $publicKey, OPENSSL_ALGO_SHA256)) {
            throw new AddonManifestException('The addon signature is invalid.', 'signature_invalid');
        }
    }

    private function safePath(string $path): bool
    {
        $segments = explode('/', $path);
        return '' !== $path && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !in_array('..', $segments, true) && !in_array('.', $segments, true) && !in_array('', $segments, true);
    }

    private function containsSymlink(string $root, string $path): bool
    {
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                return true;
            }
        }
        return false;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
