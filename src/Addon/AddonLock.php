<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonLock
{
    public function __construct(private string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create addon lock directory.');
        }
    }

    /** @return resource */
    public function acquire(string $code)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon code is invalid.', 'invalid_addon_code');
        }
        $handle = fopen($this->root.'/'.strtolower($code).'.lock', 'c+');
        if (false === $handle) throw new RuntimeException('Unable to open addon operation lock.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new AddonManifestException('Another addon operation is already running.', 'addon_busy');
        }
        return $handle;
    }

    /** @param resource $handle */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
