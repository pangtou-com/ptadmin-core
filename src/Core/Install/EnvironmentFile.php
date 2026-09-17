<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use RuntimeException;

/**
 * 以原子替换方式更新 PTAdmin 自有环境变量，保留宿主已有配置和注释。
 */
final class EnvironmentFile
{
    public function __construct(private string $path)
    {
    }

    public function put(string $key, string $value): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || preg_match('/[\x00\r\n]/', $value) === 1) {
            throw new RuntimeException('The PTAdmin environment value is invalid.');
        }

        $contents = is_file($this->path) ? file_get_contents($this->path) : '';
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read environment file: %s', $this->path));
        }

        $line = $key.'='.$this->encode($value);
        $pattern = '/^(?:export\s+)?'.preg_quote($key, '/').'\s*=.*$/m';
        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace($pattern, $line, $contents, 1);
            if (!is_string($updated)) {
                throw new RuntimeException('Unable to update the PTAdmin environment value.');
            }
        } else {
            $updated = rtrim($contents, "\r\n");
            $updated .= ($updated === '' ? '' : PHP_EOL).$line.PHP_EOL;
        }

        if ($updated === $contents) {
            return;
        }

        $this->write($updated);
    }

    private function write(string $contents): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Environment directory does not exist: %s', $directory));
        }

        $temporaryPath = tempnam($directory, '.ptadmin-env-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary environment file.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the temporary environment file.');
            }
            $permissions = is_file($this->path) ? fileperms($this->path) & 0777 : 0600;
            chmod($temporaryPath, $permissions);
            if (!rename($temporaryPath, $this->path)) {
                throw new RuntimeException(sprintf('Unable to publish environment file: %s', $this->path));
            }
            chmod($this->path, $permissions);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function encode(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
