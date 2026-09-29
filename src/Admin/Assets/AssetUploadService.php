<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Assets;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PTAdmin\Admin\Models\Asset;
use PTAdmin\Core\Config\ConfigException;
use PTAdmin\Core\Config\ConfigService;

final class AssetUploadService
{
    private const PUBLIC_DIRECTORY = 'assets';

    private const PRIVATE_DIRECTORY = 'private';

    public function __construct(private ConfigService $configs)
    {
    }

    public function maxUploadKilobytes(): int
    {
        return max(1, (int) $this->policy()['max_file_size_kilobytes']);
    }

    /**
     * 公开上传同时创建资源记录，供资源中心、Schema 字段和插件 Host API 复用。
     *
     * @param array<string, mixed> $metadata
     */
    public function uploadPublic(
        UploadedFile $file,
        string $title = '',
        string $groupCode = 'default',
        array $metadata = [],
    ): Asset {
        $this->assertUploadAllowed($file);

        $groupCode = $this->groupCode($groupCode);
        $stored = $this->store(
            $file,
            (string) config('ptadmin.storage.public_disk', 'public'),
            $this->directory(self::PUBLIC_DIRECTORY, $groupCode),
            'public',
            $metadata,
            true,
        );

        try {
            $asset = new Asset();
            $asset->forceFill([
                'title' => trim($title) === '' ? $stored->originalTitle : trim($title),
                'original_title' => $stored->originalTitle,
                'mime' => $stored->mime,
                'suffix' => $stored->suffix,
                'size' => $stored->size,
                'disk_code' => $stored->diskCode,
                'bucket' => $stored->bucket,
                'path' => $stored->path,
                'group_code' => $groupCode,
                'ref_count' => 0,
                'sha256' => $stored->sha256,
                'metadata_json' => $stored->metadata === [] ? null : $stored->metadata,
            ])->save();
        } catch (\Throwable $exception) {
            Storage::disk($stored->diskCode)->delete($stored->path);
            throw $exception;
        }

        return $asset->fresh();
    }

    /**
     * 私有上传不创建资源记录。调用方必须保存返回的存储位置并负责授权访问和文件清理。
     *
     * @param array<string, mixed> $metadata
     */
    public function uploadPrivate(
        UploadedFile $file,
        string $directory = '',
        array $metadata = [],
    ): StoredFile {
        $this->assertUploadAllowed($file);
        return $this->store(
            $file,
            (string) config('ptadmin.storage.private_disk', 'local'),
            $this->directory(self::PRIVATE_DIRECTORY, $directory),
            'private',
            $metadata,
            false,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function store(
        UploadedFile $file,
        string $diskCode,
        string $directory,
        string $visibility,
        array $metadata,
        bool $withUrl,
    ): StoredFile {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('上传文件无效。');
        }
        if ($diskCode === '') {
            throw new \InvalidArgumentException('上传存储磁盘不能为空。');
        }

        $pathname = $file->getPathname();
        $sha256 = hash_file('sha256', $pathname);
        if (!is_string($sha256)) {
            throw new \RuntimeException('无法计算上传文件摘要。');
        }

        $suffix = $this->extension($file);
        if (strlen($suffix) > 20 || preg_match('/^[a-z0-9]+$/', $suffix) !== 1) {
            $suffix = '';
        }
        $filename = Str::uuid()->toString().($suffix === '' ? '' : '.'.$suffix);
        $disk = Storage::disk($diskCode);
        if (!$disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('上传存储磁盘不支持文件写入。');
        }
        $path = $disk->putFileAs(
            $directory.'/'.date('Ymd'),
            $file,
            $filename,
            ['visibility' => $visibility],
        );
        if (!is_string($path)) {
            throw new \RuntimeException('上传文件写入存储失败。');
        }

        $url = $withUrl ? $disk->url($path) : null;

        return new StoredFile(
            mb_substr($file->getClientOriginalName(), 0, 255),
            (string) ($file->getMimeType() ?? 'application/octet-stream'),
            $suffix,
            (int) ($file->getSize() ?: 0),
            $diskCode,
            (string) config('filesystems.disks.'.$diskCode.'.bucket', ''),
            $path,
            $sha256,
            $metadata,
            $url,
        );
    }

    private function groupCode(string $groupCode): string
    {
        $groupCode = trim($groupCode);
        if ($groupCode === '') {
            return 'default';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', $groupCode) !== 1) {
            throw new \InvalidArgumentException('资源分组编码格式不正确。');
        }

        return $groupCode;
    }

    private function directory(string ...$segments): string
    {
        $directory = trim(implode('/', array_filter(array_map('trim', $segments), static fn (string $segment): bool => $segment !== '')), '/');
        if ($directory === '' || str_contains($directory, '..') || preg_match('#^[A-Za-z0-9._/-]+$#', $directory) !== 1) {
            throw new \InvalidArgumentException('上传目录格式不正确。');
        }

        return $directory;
    }

    private function assertUploadAllowed(UploadedFile $file): void
    {
        $policy = $this->policy();
        if (!(bool) $policy['enabled']) {
            throw new \InvalidArgumentException('文件上传已关闭。');
        }

        if ((int) ($file->getSize() ?: 0) > $this->maxUploadKilobytes() * 1024) {
            throw new \InvalidArgumentException('上传文件超过允许的大小。');
        }

        $extension = $this->extension($file);
        $allowed = $this->allowedExtensions($policy['allowed_extensions']);
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException('文件扩展名不在允许上传的范围内。');
        }
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $fallback = [
            'enabled' => (bool) config('ptadmin.storage.enabled', true),
            'max_file_size_kilobytes' => (int) config('ptadmin.storage.max_file_size_kilobytes', 10240),
            'allowed_extensions' => config('ptadmin.storage.allowed_extensions', ''),
            'chunk_enabled' => (bool) config('ptadmin.storage.chunk_enabled', false),
            'chunk_threshold_kilobytes' => (int) config('ptadmin.storage.chunk_threshold_kilobytes', 51200),
            'chunk_concurrency' => (int) config('ptadmin.storage.chunk_concurrency', 3),
            'chunk_retry_count' => (int) config('ptadmin.storage.chunk_retry_count', 3),
        ];

        try {
            $values = $this->configs->get('core', 'ptadmin', 'upload')['effective_values'];
        } catch (ConfigException) {
            return $fallback;
        }

        return array_replace($fallback, $values);
    }

    private function extension(UploadedFile $file): string
    {
        $suffix = strtolower(trim($file->getClientOriginalExtension(), '.'));
        if ($suffix === '') {
            $suffix = strtolower((string) $file->guessExtension());
        }
        if (strlen($suffix) > 20 || preg_match('/^[a-z0-9]+$/', $suffix) !== 1) {
            return '';
        }

        return $suffix;
    }

    /** @return list<string> */
    private function allowedExtensions(mixed $extensions): array
    {
        if (is_string($extensions)) {
            $extensions = preg_split('/[,，]/u', $extensions) ?: [];
        }
        if (!is_array($extensions)) {
            return [];
        }

        $normalized = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension)) {
                continue;
            }
            $extension = strtolower(trim($extension, " .\t\n\r\0\x0B"));
            if ($extension !== '' && strlen($extension) <= 20 && preg_match('/^[a-z0-9]+$/', $extension) === 1) {
                $normalized[$extension] = true;
            }
        }

        return array_keys($normalized);
    }
}
