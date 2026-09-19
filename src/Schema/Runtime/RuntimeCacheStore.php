<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use RuntimeException;

final class RuntimeCacheStore
{
    public function __construct(private string $path)
    {
    }

    public function read(): ?RuntimeRegistrySnapshot
    {
        if (!is_file($this->path)) {
            return null;
        }
        try {
            $value = require $this->path;
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($value) || !isset($value['format'], $value['checksum']) || (int) $value['format'] !== RuntimeRegistry::FORMAT) {
            return null;
        }
        $checksum = (string) $value['checksum'];
        $snapshot = new RuntimeRegistrySnapshot($value);
        if (!hash_equals($checksum, $snapshot->checksum())) {
            return null;
        }

        return $snapshot;
    }

    /** @param callable(): RuntimeRegistrySnapshot $fallback */
    public function load(callable $fallback): RuntimeRegistrySnapshot
    {
        return $this->read() ?? $fallback();
    }

    public function write(RuntimeRegistrySnapshot $snapshot): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Runtime cache directory cannot be created: '.$directory);
        }
        try {
            $contents = "<?php\n\nreturn ".var_export($snapshot->toArray(), true).";\n";
        } catch (\Throwable $exception) {
            throw new RuntimeException('Runtime cache cannot be serialized.', 0, $exception);
        }
        $temporary = $this->path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Runtime cache cannot be atomically replaced: '.$this->path);
        }
    }

    public function clear(): void
    {
        if (is_file($this->path) && !unlink($this->path)) {
            throw new RuntimeException('Runtime cache cannot be removed: '.$this->path);
        }
    }
}
