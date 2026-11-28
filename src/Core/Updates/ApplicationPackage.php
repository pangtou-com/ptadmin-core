<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;
use ZipArchive;

/** full 与 patch 均先生成完整目标目录；任何错误都不会写入运行中的依赖。 */
final class ApplicationPackage
{
    public function extract(string $archive, string $directory, int $maximum = 1073741824): array
    {
        if (file_exists($directory)) {
            throw new RuntimeException('归档展开目录必须不存在。');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('无法读取应用发行包。');
        }
        try {
            $text = $zip->getFromName('package.json', 16 * 1024 * 1024 + 1);
            if ($text === false || strlen($text) > 16 * 1024 * 1024 || $zip->numFiles > 100001) {
                throw new RuntimeException('发行包缺少清单或超出大小限制。');
            }
            $manifest = json_decode($text, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['protocol_version'] ?? null) !== 2 || !in_array($manifest['kind'] ?? null, ['full', 'patch'], true)) {
                throw new RuntimeException('不支持的应用发行包协议。');
            }
            PackageManifest::validate($manifest['target'] ?? []);
            if (!is_array($manifest['upsert'] ?? null) || !is_array($manifest['delete'] ?? null) || !array_is_list($manifest['delete'])) {
                throw new RuntimeException('补丁操作清单无效。');
            }
            if ($manifest['kind'] === 'full' && ($manifest['upsert'] !== $manifest['target']['files'] || $manifest['delete'] !== [] || ($manifest['source'] ?? null) !== null)) {
                throw new RuntimeException('完整包必须包含完整目标且没有删除操作。');
            }
            if ($manifest['kind'] === 'patch') {
                foreach (['build_id', 'tree_sha256', 'baseline_sha256'] as $key) {
                    if (!preg_match('/^[a-f0-9]{64}$/D', $manifest['source'][$key] ?? '')) {
                        throw new RuntimeException('补丁缺少准确来源身份。');
                    }
                }
            }
            foreach ($manifest['upsert'] as $path => $file) {
                if (!isset($manifest['target']['files'][$path]) || $manifest['target']['files'][$path] !== $file) {
                    throw new RuntimeException('补丁内容不属于目标清单。');
                }
            }
            foreach ($manifest['delete'] as $path) {
                PackageManifest::path($path);
                if (!PackageManifest::managed($path) || isset($manifest['target']['files'][$path])) {
                    throw new RuntimeException('补丁删除路径不允许。');
                }
            }
            if (count(array_unique($manifest['delete'])) !== count($manifest['delete'])) {
                throw new RuntimeException('补丁包含重复删除操作。');
            }
            $allowed = array_fill_keys(array_map(static fn ($path) => 'payload/'.$path, array_keys($manifest['upsert'])), true);
            $allowed['package.json'] = true;
            $seen = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                PackageManifest::path($name);
                $ops = $attributes = 0;
                $zip->getExternalAttributesIndex($i, $ops, $attributes);
                if (!isset($allowed[$name]) || isset($seen[strtolower($name)]) || ($stat['encryption_method'] ?? 0) !== 0
                    || ($ops === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000], true))) {
                    throw new RuntimeException('发行包包含未声明、重复或特殊文件：'.$name);
                }
                $seen[strtolower($name)] = true;
                $total += $stat['size'];
                if ($total > $maximum || $stat['size'] > 256 * 1024 * 1024) {
                    throw new RuntimeException('发行包解压大小超限。');
                }
            }
            if (count($seen) !== count($allowed)) {
                throw new RuntimeException('发行包缺少已声明文件。');
            }
            UpdateFiles::directory($directory);
            if (!$zip->extractTo($directory)) {
                throw new RuntimeException('发行包解压失败。');
            }
            foreach ($manifest['upsert'] as $path => $file) {
                if (PackageManifest::fingerprint($directory.'/payload', $path) !== $file['sha256'] || filesize($directory.'/payload/'.$path) !== $file['size']) {
                    throw new RuntimeException('发行包文件摘要不符：'.$path);
                }
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function prepare(string $bundle, string $stage, ?string $sourceRoot = null): array
    {
        $manifest = UpdateFiles::read($bundle.'/package.json');
        PackageManifest::validate($manifest['target']);
        if (file_exists($stage)) {
            throw new RuntimeException('目标准备目录必须不存在。');
        }
        if ($manifest['kind'] === 'patch') {
            if ($sourceRoot === null) {
                throw new RuntimeException('补丁缺少来源目录。');
            }
            $source = UpdateFiles::read($sourceRoot.'/'.PackageManifest::IDENTITY);
            PackageManifest::verify($sourceRoot, $source, false);
            foreach (['build_id', 'tree_sha256', 'baseline_sha256'] as $key) {
                if ($source[$key] !== $manifest['source'][$key]) {
                    throw new RuntimeException('补丁来源与当前官方构建不符。');
                }
            }
            foreach (['vendor', 'composer.json', 'composer.lock'] as $path) {
                UpdateFiles::copy($sourceRoot.'/'.$path, $stage.'/'.$path);
            }
            foreach ($manifest['delete'] as $path) {
                if (!isset($source['files'][$path]) || !is_file($stage.'/'.$path) || !unlink($stage.'/'.$path)) {
                    throw new RuntimeException('无法删除补丁来源文件：'.$path);
                }
                // 只移除删除文件留下的空目录，支持目录变为普通文件。
                for ($parent = dirname($stage.'/'.$path); $parent !== $stage.'/vendor' && $parent !== $stage; $parent = dirname($parent)) {
                    if (scandir($parent) !== ['.', '..'] || !rmdir($parent)) {
                        break;
                    }
                }
            }
        }
        foreach ($manifest['upsert'] as $path => $_) {
            UpdateFiles::copy($bundle.'/payload/'.$path, $stage.'/'.$path);
        }
        PackageManifest::verify($stage, $manifest['target'], false);
        $this->verifyShell($stage, $manifest['target']);
        UpdateFiles::write($stage.'/'.PackageManifest::IDENTITY, $manifest['target']);
        return $manifest['target'];
    }

    public function verifyShell(string $root, array $target): void
    {
        $shell = $target['shell'];
        $actual = (new \PTAdmin\Core\Install\AdminFrontendManifest($target['version'], $shell['api_protocol'], $shell['schema_protocol']))
            ->verify($root.'/vendor/ptadmin/core/resources/admin-frontend');
        if ($actual['frontend_version'] !== $shell['version'] || $actual['artifact_sha256'] !== $shell['artifact_sha256']) {
            throw new RuntimeException('应用包内置 Shell 与目标声明不一致。');
        }
    }
}
