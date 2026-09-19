<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use JsonException;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use RuntimeException;

final class InstallStateStore
{
    public function __construct(private string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function statePath(): string
    {
        return $this->directory.'/state.json';
    }

    public function lockPath(): string
    {
        return $this->directory.'/install.lock';
    }

    public function installedPath(): string
    {
        return $this->directory.'/installed.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readState(): ?array
    {
        return $this->readJson($this->statePath(), 'installation state');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readInstalled(): ?array
    {
        return $this->readJson($this->installedPath(), 'installed marker');
    }

    public function isInstalled(): bool
    {
        $installed = $this->readInstalled();

        return is_array($installed)
            && ($installed['status'] ?? null) === 'installed'
            && is_string($installed['core_version'] ?? null)
            && is_string($installed['web_prefix'] ?? null)
            && WebPrefixGenerator::isValid($installed['web_prefix']);
    }
    
    /**
     * @param array<string, mixed> $state
     * @return array
     * @throws JsonException
     */
    public function writeState(array $state): array
    {
        $state['updated_at'] = gmdate(DATE_ATOM);
        $this->writeJson($this->statePath(), $state);

        return $state;
    }
    
    /**
     * @param string $coreVersion
     * @param string $webPrefix
     * @return array{status:string, core_version:string, web_prefix:string, installed_at:string}
     * @throws JsonException
     */
    public function markInstalled(string $coreVersion, string $webPrefix): array
    {
        if (!WebPrefixGenerator::isValid($webPrefix)) {
            throw new InvalidInstallStateException('The PTAdmin web prefix is invalid.');
        }

        $marker = [
            'status' => 'installed',
            'core_version' => $coreVersion,
            'web_prefix' => $webPrefix,
            'installed_at' => gmdate(DATE_ATOM),
        ];
        $this->writeJson($this->installedPath(), $marker);

        return $marker;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path, string $label): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new InvalidInstallStateException(sprintf('Unable to read %s: %s', $label, $path));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidInstallStateException(
                sprintf('The %s is not valid JSON: %s', $label, $path),
                previous: $exception
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidInstallStateException(sprintf('The %s must contain a JSON object.', $label));
        }

        return $decoded;
    }
    
    /**
     * @param string $path
     * @param array<string, mixed> $value
     * @throws JsonException
     */
    private function writeJson(string $path, array $value): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create install state directory: %s', $this->directory));
        }

        $temporaryPath = tempnam($this->directory, 'ptadmin-state-');
        if (false === $temporaryPath) {
            throw new RuntimeException('Unable to create temporary install state file.');
        }

        try {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (false === file_put_contents($temporaryPath, $encoded, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to write install state: %s', $path));
            }

            chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException(sprintf('Unable to publish install state: %s', $path));
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
