<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;

/** 更新执行及脱离 Laravel 的恢复工具共用的文件操作。 */
final class UpdateFiles
{
    public static function write(string $path, array $document): void
    {
        self::directory(dirname($path));
        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temp, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('无法写入更新记录。');
        }
        chmod($temp, 0600);
        if (!rename($temp, $path)) {
            throw new RuntimeException('无法保存更新记录。');
        }
    }

    public static function read(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('更新记录格式无效。');
        }
        return $value;
    }

    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('无法创建更新工作目录。');
        }
    }

    public static function copy(string $source, string $target): void
    {
        if (is_link($source)) {
            throw new RuntimeException('更新范围包含符号链接，请通过部署流程更新。');
        }
        if (is_dir($source)) {
            self::directory($target);
            chmod($target, fileperms($source) & 0777);
            foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $file) {
                self::copy($file->getPathname(), $target.'/'.$file->getFilename());
            }
        } elseif (is_file($source)) {
            self::directory(dirname($target));
            if (!copy($source, $target)) {
                throw new RuntimeException('无法复制更新文件。');
            }
            chmod($target, fileperms($source) & 0777);
        } else {
            throw new RuntimeException('更新范围包含特殊或缺失文件。');
        }
    }

    public static function move(string $source, string $target): void
    {
        if (!rename($source, $target)) {
            throw new RuntimeException('更新文件切换失败，请检查权限和磁盘空间后恢复。');
        }
    }
}
