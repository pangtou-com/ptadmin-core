<?php

declare(strict_types=1);

namespace PTAdmin\Core;

use PTAdmin\Core\Exceptions\ApplicationIdentityUnavailableException;
use RuntimeException;

final class ApplicationIdentity
{
    private const FORMAT_VERSION = 1;

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Loads an existing identity. Missing or invalid files are never rebuilt implicitly.
     *
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            throw new ApplicationIdentityUnavailableException(
                sprintf('PTAdmin application identity is missing: %s', $this->path)
            );
        }

        $contents = file_get_contents($this->path);
        if (false === $contents) {
            throw new ApplicationIdentityUnavailableException(
                sprintf('PTAdmin application identity cannot be read: %s', $this->path)
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApplicationIdentityUnavailableException(
                sprintf('PTAdmin application identity is not valid JSON: %s', $this->path),
                previous: $exception
            );
        }

        if (!is_array($decoded)) {
            throw new ApplicationIdentityUnavailableException('PTAdmin application identity must be an object.');
        }

        $identity = $this->normalize($decoded);
        if (false === openssl_pkey_get_private($identity['private_key'])) {
            throw new ApplicationIdentityUnavailableException('PTAdmin application identity private key is invalid.');
        }

        if (false === openssl_pkey_get_public($identity['public_key'])) {
            throw new ApplicationIdentityUnavailableException('PTAdmin application identity public key is invalid.');
        }

        return $identity;
    }

    /**
     * Creates an identity for installation, reusing an existing valid identity.
     *
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    public function createForInstall(): array
    {
        if (is_file($this->path)) {
            return $this->load();
        }

        return $this->write($this->generate());
    }

    /**
     * Explicitly creates a new application identity. Callers must handle authorization migration.
     *
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    public function rebuild(): array
    {
        return $this->write($this->generate());
    }

    /**
     * @param array<string, mixed> $value
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    private function normalize(array $value): array
    {
        $formatVersion = $value['format_version'] ?? null;
        $instanceId = $value['application_instance_id'] ?? null;
        $publicKey = $value['public_key'] ?? null;
        $privateKey = $value['private_key'] ?? null;
        $createdAt = $value['created_at'] ?? null;

        if (self::FORMAT_VERSION !== $formatVersion
            || !is_string($instanceId)
            || !preg_match('/^[0-9a-f]{32}$/', $instanceId)
            || !is_string($publicKey)
            || '' === trim($publicKey)
            || !is_string($privateKey)
            || '' === trim($privateKey)
            || !is_string($createdAt)
            || '' === trim($createdAt)) {
            throw new ApplicationIdentityUnavailableException('PTAdmin application identity has an invalid structure.');
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'application_instance_id' => $instanceId,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    private function generate(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if (false === $key) {
            throw new RuntimeException('Unable to generate PTAdmin application key pair.');
        }

        $privateKey = '';
        if (!openssl_pkey_export($key, $privateKey) || '' === $privateKey) {
            throw new RuntimeException('Unable to export PTAdmin application private key.');
        }

        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['key']) || !is_string($details['key'])) {
            throw new RuntimeException('Unable to export PTAdmin application public key.');
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'application_instance_id' => bin2hex(random_bytes(16)),
            'public_key' => $details['key'],
            'private_key' => $privateKey,
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * @param array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string} $identity
     * @return array{format_version:int, application_instance_id:string, public_key:string, private_key:string, created_at:string}
     */
    private function write(array $identity): array
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create PTAdmin identity directory: %s', $directory));
        }

        $temporaryPath = tempnam($directory, 'ptadmin-identity-');
        if (false === $temporaryPath) {
            throw new RuntimeException('Unable to create temporary PTAdmin identity file.');
        }

        try {
            $encoded = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (false === file_put_contents($temporaryPath, $encoded, LOCK_EX)) {
                throw new RuntimeException('Unable to write temporary PTAdmin identity file.');
            }

            chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $this->path)) {
                throw new RuntimeException(sprintf('Unable to publish PTAdmin identity file: %s', $this->path));
            }
            chmod($this->path, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return $identity;
    }
}
