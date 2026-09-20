<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use RuntimeException;

final class RuntimeCacheStore
{
    private ?RuntimeRegistrySnapshot $loaded = null;

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
            if (!is_array($value) || !isset($value['format'], $value['sources'], $value['resources'], $value['checksum'])
                || !is_int($value['format']) || $value['format'] !== RuntimeRegistry::FORMAT
                || !is_array($value['sources'])
                || !is_array($value['resources']) || !is_string($value['checksum'])
            ) {
                return null;
            }
            foreach ($value['sources'] as $source => $checksum) {
                if (!is_string($source) || !is_string($checksum)
                    || preg_match('/^(?:core\/ptadmin|app\/host|addon\/[a-z][a-z0-9_-]{0,63})$/', $source) !== 1
                    || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                    return null;
                }
            }
            $snapshot = new RuntimeRegistrySnapshot($value);
            if (!hash_equals($value['checksum'], $snapshot->checksum())) {
                return null;
            }

            return $snapshot;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param callable(): RuntimeRegistrySnapshot $fallback */
    public function load(callable $fallback): RuntimeRegistrySnapshot
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        return $this->loaded = $this->read() ?? $fallback();
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
        $this->loaded = $snapshot;
    }

    public function clear(): void
    {
        $this->loaded = null;
        if (is_file($this->path) && !unlink($this->path)) {
            throw new RuntimeException('Runtime cache cannot be removed: '.$this->path);
        }
    }
}
