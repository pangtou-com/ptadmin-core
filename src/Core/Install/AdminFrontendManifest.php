<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Composer\Semver\Semver;
use JsonException;
use RuntimeException;

/**
 * 校验核心包携带或项目当前使用的后台前端清单，确保发布前文件完整且协议兼容。
 */
final class AdminFrontendManifest
{
    public function __construct(
        private string $backendVersion,
        private int $apiProtocol,
        private int $schemaProtocol
    ) {
    }

    /** @return array<string, mixed> */
    public function verify(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('The PTAdmin frontend directory is unavailable.');
        }

        $manifestPath = $root.'/manifest.json';
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if ($contents === false) {
            throw new RuntimeException('The PTAdmin frontend manifest is unavailable.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The PTAdmin frontend manifest is invalid.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('The PTAdmin frontend manifest must be an object.');
        }

        $this->assertHeader($decoded);
        $files = $decoded['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new RuntimeException('The PTAdmin frontend manifest has no files.');
        }

        $previousPath = '';
        $verified = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('The PTAdmin frontend manifest contains an invalid file entry.');
            }
            $path = $file['path'] ?? null;
            $size = $file['size'] ?? null;
            $sha256 = $file['sha256'] ?? null;
            if (!is_string($path)
                || !$this->isSafePath($path)
                || ($previousPath !== '' && strcmp($previousPath, $path) >= 0)
                || !is_int($size)
                || $size < 0
                || !is_string($sha256)
                || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
                throw new RuntimeException('The PTAdmin frontend manifest contains an invalid file definition.');
            }

            $absolutePath = $root.'/'.$path;
            $realPath = realpath($absolutePath);
            if ($realPath === false
                || !str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
                || !is_file($realPath)
                || $this->containsSymlink($root, $path)
                || filesize($realPath) !== $size
                || hash_file('sha256', $realPath) !== $sha256) {
                throw new RuntimeException(sprintf('The PTAdmin frontend file failed verification: %s', $path));
            }

            $verified[] = ['path' => $path, 'size' => $size, 'sha256' => $sha256];
            $previousPath = $path;
        }

        if (!in_array(ltrim((string) $decoded['entry'], './'), array_column($verified, 'path'), true)) {
            throw new RuntimeException('The PTAdmin frontend entry is not included in the manifest.');
        }
        $canonical = json_encode($verified, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (hash('sha256', $canonical) !== ($decoded['artifact_sha256'] ?? null)) {
            throw new RuntimeException('The PTAdmin frontend artifact checksum is invalid.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function assertHeader(array $manifest): void
    {
        if (($manifest['manifest_version'] ?? null) !== 1
            || !is_string($manifest['frontend_version'] ?? null)
            || !is_string($manifest['backend_constraint'] ?? null)
            || ($manifest['api_protocol'] ?? null) !== $this->apiProtocol
            || ($manifest['schema_protocol'] ?? null) !== $this->schemaProtocol
            || ($manifest['entry'] ?? null) !== './index.html'
            || !is_string($manifest['artifact_sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/', (string) $manifest['artifact_sha256']) !== 1) {
            throw new RuntimeException('The PTAdmin frontend manifest header is incompatible.');
        }

        try {
            $compatible = Semver::satisfies($this->backendVersion, $manifest['backend_constraint']);
        } catch (\UnexpectedValueException $exception) {
            throw new RuntimeException('The PTAdmin frontend backend constraint is invalid.', previous: $exception);
        }
        if (!$compatible) {
            throw new RuntimeException('The PTAdmin frontend is incompatible with this backend version.');
        }
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        $segments = explode('/', $path);

        return !in_array('.', $segments, true)
            && !in_array('..', $segments, true)
            && !in_array('', $segments, true);
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
}
