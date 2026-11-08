<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Illuminate\Support\Facades\Http;
use ZipArchive;

/** 官方构建包入口固定；在展开前限制数量、体积、文件类型及路径。 */
final class OfficialShellArchive
{
    public function download(array $package, string $directory, callable $heartbeat): string
    {
        $maximum = (int) config('ptadmin-updates.max_archive_bytes');
        if ($package['size'] > $maximum) {
            throw new UpdateException('Shell 构建包超过下载上限。', 'archive_too_large');
        }
        $response = Http::timeout(300)->connectTimeout(10)->withOptions(['allow_redirects' => false, 'stream' => true])->get($package['url']);
        if (!$response->successful()) {
            throw new UpdateException('Shell 构建包下载失败或版本已撤回。', 'download_failed');
        }
        $stream = $response->toPsrResponse()->getBody();
        $path = $directory.'/build.zip';
        $output = fopen($path, 'wb');
        if ($output === false) {
            throw new UpdateException('无法创建下载文件。', 'download_failed');
        }
        $size = 0;
        try {
            while (true) {
                $bytes = $stream->read(65536);
                if ($bytes === '' && $stream->eof()) {
                    break;
                }
                if ($bytes === '') {
                    throw new UpdateException('下载中断，请重试。', 'download_failed');
                }
                $size += strlen($bytes);
                if ($size > $package['size'] || $size > $maximum || fwrite($output, $bytes) !== strlen($bytes)) {
                    throw new UpdateException('下载大小异常或磁盘写入失败。', 'download_failed');
                }
                $heartbeat($size);
            }
        } finally {
            $stream->close();
            fclose($output);
        }
        if ($size !== $package['size'] || !hash_equals($package['sha256'], hash_file('sha256', $path))) {
            throw new UpdateException('Shell 下载大小或 SHA-256 与发行记录不一致。', 'invalid_archive');
        }
        return $path;
    }

    public function extract(string $archive, string $directory): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UpdateException('Shell 构建包无法读取。', 'invalid_archive');
        }
        try {
            if ($zip->numFiles > 20000) {
                throw new UpdateException('Shell 包文件数量超过上限。', 'invalid_archive');
            }
            $seen = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                $path = rtrim($name, '/');
                $segments = explode('/', $path);
                if ($path === '' || preg_match('/[\\\\:\x00-\x1f]/', $path) || str_starts_with($path, '/')
                    || array_intersect($segments, ['', '.', '..']) !== [] || isset($seen[strtolower($path)])
                    || in_array(strtolower($segments[0]), ['modules', 'plugins'], true)
                    || preg_match('/(?:^|\/)[.]|[.](?:php[0-9]?|phtml|phar|cgi|pl|py|sh|shtml|asp|aspx)(?:[.]|$)/i', $path)
                    || ($stat['encryption_method'] ?? 0) !== 0 || $stat['size'] > 64 * 1024 * 1024) {
                    throw new UpdateException('Shell 包含不允许的路径、重复文件或加密条目。', 'unsafe_archive');
                }
                $seen[strtolower($path)] = true;
                $total += $stat['size'];
                if ($total > config('ptadmin-updates.max_uncompressed_bytes')) {
                    throw new UpdateException('Shell 解压体积超过上限。', 'unsafe_archive');
                }
                $ops = $attributes = 0;
                $zip->getExternalAttributesIndex($index, $ops, $attributes);
                if ($ops === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0040000, 0100000], true)) {
                    throw new UpdateException('Shell 包含符号链接或特殊文件。', 'unsafe_archive');
                }
            }
            UpdateFiles::directory($directory);
            if (!$zip->extractTo($directory)) {
                throw new UpdateException('Shell 解压失败。', 'invalid_archive');
            }
        } finally {
            $zip->close();
        }
    }
}
