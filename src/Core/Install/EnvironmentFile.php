<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Lines;
use Dotenv\Parser\Parser;
use RuntimeException;

/**
 * 以原子替换方式更新 PTAdmin 自有环境变量，保留宿主已有配置和注释。
 */
final class EnvironmentFile
{
    public function __construct(private string $path)
    {
    }

    public function get(string $key): ?string
    {
        try {
            $value = Dotenv::parse($this->contents())[$key] ?? null;
            // 与 Laravel env() 一样移除保留在 dotenv 值中的一层引号。
            if ($value !== null && preg_match('/\A([\'"])(.*)\1\z/', $value, $matches) === 1) {
                return $matches[2];
            }

            return $value;
        } catch (InvalidFileException) {
            throw new RuntimeException('The PTAdmin environment file syntax is invalid.');
        }
    }

    public function put(string $key, string $value): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || !self::isValidValue($value)) {
            throw new RuntimeException('The PTAdmin environment value is invalid.');
        }

        $contents = $this->contents();

        $line = $key.'='.$this->encode($value);
        try {
            $parser = new Parser();
            $entries = $parser->parse($contents);
            $rawEntries = Lines::process(preg_split('/\r\n|\n|\r/', $contents));
            $offset = 0;
            $updated = '';
            $found = false;
            // 按 dotenv 的完整赋值定位，避免匹配到其他多行值内部的同名文本。
            foreach ($entries as $index => $entry) {
                $pattern = '~(?:\A|(?<=[\r\n]))'
                    .str_replace("\n", '(?:\r\n|\n|\r)', preg_quote($rawEntries[$index], '~'))
                    .'(?=\r\n|\n|\r|\z)~';
                if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    throw new RuntimeException('Unable to locate the PTAdmin environment value.');
                }
                [$original, $start] = $matches[0];
                $replace = $entry->getName() === $key;
                $updated .= substr($contents, $offset, $start - $offset).($replace ? $line : $original);
                $offset = $start + strlen($original);
                $found = $found || $replace;
            }
            $updated .= substr($contents, $offset);
            if (!$found) {
                $updated = rtrim($updated, "\r\n");
                $updated .= ($updated === '' ? '' : PHP_EOL).$line.PHP_EOL;
            }
            $parser->parse($updated);
        } catch (InvalidFileException) {
            // 不回传解析器原始错误，避免泄漏环境文件中的密码等配置。
            throw new RuntimeException('The PTAdmin environment file syntax is invalid.');
        }

        if ($updated === $contents) {
            return;
        }

        $this->write($updated);
    }

    /** 安装入口与实际写入共用相同约束，避免在迁移后才发现配置无法保存。 */
    public static function isValidValue(string $value): bool
    {
        return preg_match('/[\x00\r\n]/', $value) !== 1;
    }

    private function contents(): string
    {
        $contents = is_file($this->path) ? file_get_contents($this->path) : '';
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read environment file: %s', $this->path));
        }

        return $contents;
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
        // dotenv 先去掉外层引号，Laravel 再解释保留字或去掉一层引号。
        // 给这些字符串保留一层引号，避免重启后被转成布尔值、空值或丢失字面引号。
        if (in_array(strtolower($value), ['true', 'false', 'empty', 'null', '(true)', '(false)', '(empty)', '(null)'], true)
            || preg_match('/\A([\'"])(.*)\1\z/', $value) === 1) {
            $value = '"'.$value.'"';
        }
        if ($value !== '' && preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
