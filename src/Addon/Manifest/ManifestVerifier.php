<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Manifest;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use JsonException;
use PTAdmin\Addon\Exception\AddonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * 插件 manifest、兼容约束和正式包签名的验证入口。
 *
 * 运行时应使用 verifyRuntime()，它只读取并校验 manifest 元数据，不扫描插件文件。
 */
final class ManifestVerifier
{
    public function __construct(
        private string $backendVersion = '0.1.0',
        private int $schemaProtocol = 1,
        private ?TrustPolicy $trustPolicy = null
    )
    {
    }

    /**
     * 验证插件目录及其 manifest；要求签名时同时执行签名校验和 trusted 发布者策略。
     * 市场入库可关闭当前宿主版本匹配，但约束语法、签名和信任策略仍执行；安装保持默认检查。
     *
     * @return array<string, mixed> 已附加 verified_files 的规范化 manifest 数据
     */
    public function verify(string $directory, bool $requireSignature = true, bool $checkBackendCompatibility = true): array
    {
        $loaded = $this->loadManifest($directory);
        $root = $loaded['root'];
        $manifest = $loaded['manifest'];
        $this->assertHeader($manifest, $checkBackendCompatibility);
        $files = $this->buildFileList($root, $manifest);
        if ($requireSignature) {
            $this->verifySignature($manifest, $files);
            $this->trustPolicy?->assertTrusted($manifest);
        }
        $manifest['verified_files'] = $files;
        return $manifest;
    }

    /**
     * 读取运行时 manifest 元数据。
     *
     * 运行时加载允许本地插件二次开发，因此这里只校验 manifest 结构和兼容性，不处理签名、文件清单或文件摘要。
     * 正式包签名由未来的 artifact 安装流程负责。
     *
     * @return array<string, mixed>
     */
    public function verifyRuntime(string $directory): array
    {
        $manifest = $this->loadManifest($directory)['manifest'];
        $this->assertHeader($manifest);

        return $manifest;
    }

    /** @return array{root:string,manifest:array<string,mixed>} */
    private function loadManifest(string $directory): array
    {
        $root = realpath($directory);
        if (false === $root || !is_dir($root)) {
            throw new AddonException('The addon package directory is unavailable.', 'package_unavailable');
        }
        $manifestPath = $root.'/manifest.json';
        $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        if (false === $raw) {
            throw new AddonException('The addon manifest is unavailable.');
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AddonException('The addon manifest is invalid.', 'invalid_addon_manifest');
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new AddonException('The addon manifest must be an object.');
        }

        return ['root' => $root, 'manifest' => $manifest];
    }

    /**
     * 生成前端会话和诊断使用的稳定 manifest 摘要，摘要覆盖 manifest 与已验证文件清单。
     *
     * @param array<string, mixed> $manifest verify() 返回的 manifest
     * @return string
     * @throws JsonException
     */
    public function manifestSha256(array $manifest): string
    {
        $files = is_array($manifest['verified_files'] ?? null) ? $manifest['verified_files'] : [];

        return 'sha256:'.hash('sha256', $this->signingPayload($manifest, $files));
    }

    /**
     * 构造签名方和验证方共享的规范化载荷；签名值本身与运行时附加字段不进入签名内容。
     *
     * @param array<string, mixed> $manifest
     * @param array<int, array<string, mixed>> $files
     * @return string
     * @throws JsonException
     */
    public function signingPayload(array $manifest, array $files): string
    {
        if (is_array($manifest['signature'] ?? null)) {
            unset($manifest['signature']['value']);
        }
        unset($manifest['verified_files']);
        // files is a legacy author-maintained list. File integrity is derived from disk.
        unset($manifest['files']);

        return json_encode(
            $this->canonicalize(['manifest' => $manifest, 'files' => $files]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** @param array<string, mixed> $manifest */
    private function assertHeader(array $manifest, bool $checkBackendCompatibility = true): void
    {
        $code = (string) ($manifest['code'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1 || '' === $version || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new AddonException('The addon code or version is invalid.');
        }
        if ((int) ($manifest['schema_protocol'] ?? $this->schemaProtocol) !== $this->schemaProtocol) {
            throw new AddonException('The addon schema protocol is incompatible.', 'protocol_incompatible');
        }
        $compatibilityMap = is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [];
        $compatibility = $compatibilityMap['ptadmin/core'] ?? null;
        if (is_string($compatibility) && '' !== trim($compatibility)) {
            try {
                (new VersionParser())->parseConstraints($compatibility);
                if ($checkBackendCompatibility && !Semver::satisfies($this->backendVersion, $compatibility)) {
                    throw new AddonException('The addon is incompatible with this backend.', 'backend_incompatible');
                }
            } catch (\UnexpectedValueException $exception) {
                throw new AddonException('The addon compatibility constraint is invalid.', 'invalid_constraint');
            }
        }
    }

    /**
     * 根据磁盘实际内容生成文件摘要，并为每个文件记录大小和 SHA-256。
     * 文件摘要属于验证结果，不要求插件开发者维护 manifest.files 清单。
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function buildFileList(string $root, array $manifest): array
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new AddonException('The addon package contains a symlink.', 'unsafe_path');
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
        $paths = $actual;
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $result = [];
        foreach ($paths as $path) {
            $absolute = $root.'/'.$path;
            $real = realpath($absolute);
            if (false === $real || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || !is_file($real) || $this->containsSymlink($root, $path)) {
                throw new AddonException('The addon file failed path verification: '.$path, 'unsafe_path');
            }
            $result[] = ['path' => $path, 'size' => filesize($real), 'sha256' => hash_file('sha256', $real)];
        }
        return $result;
    }

    /**
     * 使用插件声明的公钥验证规范化载荷；公钥是否受当前部署信任由 TrustPolicy 继续判断。
     *
     * @param array<string, mixed> $manifest
     * @param array<int, array<string, mixed>> $files
     * @throws JsonException
     */
    private function verifySignature(array $manifest, array $files): void
    {
        $signature = (string) ($manifest['signature']['value'] ?? '');
        $publicKey = (string) ($manifest['signature']['public_key'] ?? '');
        if ('' === $signature || '' === $publicKey) {
            throw new AddonException('The addon signature is required.', 'signature_missing');
        }
        $canonical = $this->signingPayload($manifest, $files);
        $decoded = base64_decode($signature, true);
        if (!is_string($decoded) || 1 !== openssl_verify($canonical, $decoded, $publicKey, OPENSSL_ALGO_SHA256)) {
            throw new AddonException('The addon signature is invalid.', 'signature_invalid');
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
