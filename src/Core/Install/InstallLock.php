<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Closure;
use PTAdmin\Core\Install\Exceptions\InstallAlreadyRunningException;
use RuntimeException;

final class InstallLock
{
    public function __construct(private string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function whileLocked(Closure $operation): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create install state directory: %s', $directory));
        }

        $handle = fopen($this->path, 'c+');
        if (false === $handle) {
            throw new RuntimeException(sprintf('Unable to open install lock: %s', $this->path));
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new InstallAlreadyRunningException('Another PTAdmin installation is already running.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
