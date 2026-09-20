<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Assets;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PTAdmin\Admin\Models\Asset;

final class AssetUploadService
{
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
        $maximumBytes = max(1, (int) config('ptadmin.storage.max_upload_kilobytes', 10240)) * 1024;
        if ((int) ($file->getSize() ?: 0) > $maximumBytes) {
            throw new \InvalidArgumentException('上传文件超过允许的大小。');
        }

        $groupCode = $this->groupCode($groupCode);
        $stored = $this->store(
            $file,
            (string) config('ptadmin.storage.public_disk', 'public'),
            $this->directory((string) config('ptadmin.storage.public_directory', 'assets'), $groupCode),
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
        $baseDirectory = (string) config('ptadmin.storage.private_directory', 'private');

        return $this->store(
            $file,
            (string) config('ptadmin.storage.private_disk', 'local'),
            $this->directory($baseDirectory, $directory),
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

        $suffix = strtolower(trim($file->getClientOriginalExtension(), '.'));
        if ($suffix === '') {
            $suffix = strtolower((string) $file->guessExtension());
        }
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
}
