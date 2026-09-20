<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Assets;

/**
 * 文件写入存储后的定位信息。私有上传只返回该对象，不隐式创建公共资源记录。
 */
final class StoredFile
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $originalTitle,
        public readonly string $mime,
        public readonly string $suffix,
        public readonly int $size,
        public readonly string $diskCode,
        public readonly string $bucket,
        public readonly string $path,
        public readonly string $sha256,
        public readonly array $metadata = [],
        public readonly ?string $url = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $file = [
            'original_title' => $this->originalTitle,
            'mime' => $this->mime,
            'suffix' => $this->suffix,
            'size' => $this->size,
            'disk_code' => $this->diskCode,
            'bucket' => $this->bucket,
            'path' => $this->path,
            'sha256' => $this->sha256,
            'metadata' => $this->metadata,
        ];
        if ($this->url !== null) {
            $file['url'] = $this->url;
        }

        return $file;
    }
}
