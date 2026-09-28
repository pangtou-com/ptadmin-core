<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Contracts\Encryption\Encrypter;
use PTAdmin\Cloud\Contracts\CloudSessionStoreInterface;
use RuntimeException;

final class EncryptedCloudSessionStore implements CloudSessionStoreInterface
{
    public function __construct(private string $path, private Encrypter $encrypter)
    {
    }

    public function get(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $contents = file_get_contents($this->path);
        if ($contents === false || $contents === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->encrypter->decrypt($contents), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new CloudException('云市场会话文件无法解密。', 'cloud_session_invalid', previous: $exception);
        }
        return is_array($decoded) ? $decoded : null;
    }

    public function put(array $session): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create cloud session directory.');
        }
        try {
            $encoded = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $encrypted = $this->encrypter->encrypt($encoded);
        } catch (\Throwable $exception) {
            throw new CloudException('云市场会话无法保存。', 'cloud_session_store_failed', previous: $exception);
        }
        $temporary = tempnam($directory, 'ptadmin-cloud-session-');
        if ($temporary === false || file_put_contents($temporary, $encrypted, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write cloud session file.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish cloud session file.');
        }
        chmod($this->path, 0600);
    }

    public function forget(): void
    {
        if (is_file($this->path) && !unlink($this->path)) {
            throw new RuntimeException('Unable to remove cloud session file.');
        }
    }
}
