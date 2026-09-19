<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class AddonInstaller
{
    public function __construct(
        private AddonManifestVerifier $verifier,
        private AddonPackageExtractor $extractor,
        private AddonLifecycleStore $lifecycle,
        private AddonFrontendPublisher $frontend,
        private string $addonsPath,
        private string $stagingPath,
        private bool $requireSignature = true,
        private ?AddonResourceSynchronizerInterface $resourceSynchronizer = null,
        private ?ConnectionInterface $database = null,
        private ?AddonLock $locks = null,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->stagingPath = rtrim($stagingPath, '/');
    }

    /** @return array<string, mixed> */
    public function install(
        string $source,
        string $webPrefix,
        bool $update = false,
        ?string $expectedCode = null,
        ?string $expectedSha256 = null,
    ): array
    {
        $this->verifyArtifactChecksum($source, $expectedSha256);
        $stage = $this->stagingPath.'/'.bin2hex(random_bytes(8));
        if (!is_dir($stage) && !mkdir($stage, 0700, true) && !is_dir($stage)) {
            throw new RuntimeException('Unable to create addon task staging directory.');
        }
        try {
            $package = $this->extractor->extract($source, $stage.'/package');
            $manifest = $this->verifier->verify($package, $this->requireSignature);
            $code = (string) $manifest['code'];
            if ($expectedCode !== null && strcasecmp($expectedCode, $code) !== 0) {
                throw new AddonManifestException('The addon code does not match the requested operation.', 'addon_code_mismatch');
            }
            $lockHandle = $this->locks?->acquire($code);
            $target = $this->addonsPath.'/'.$code;
            $previousLifecycle = $this->lifecycle->read($code);
            $lifecycleCaptured = true;
            if (!$update && is_dir($target)) {
                throw new AddonManifestException('The addon is already installed.', 'addon_already_installed');
            }
            if ($update && !is_dir($target)) {
                throw new AddonManifestException('The addon is not installed.', 'addon_not_installed');
            }
            if (($previousLifecycle['state'] ?? null) === 'disposed') {
                $this->lifecycle->transition($code, 'discovered');
            }
            $this->lifecycle->transition($code, 'validating', ['version' => $manifest['version']]);
            $this->lifecycle->transition($code, 'loading');
            if (!is_dir($this->addonsPath) && !mkdir($this->addonsPath, 0755, true) && !is_dir($this->addonsPath)) {
                throw new RuntimeException('Unable to create addon directory.');
            }
            $replacement = $stage.'/replacement';
            rename($package, $replacement);
            $backup = $target.'.backup-'.bin2hex(random_bytes(5));
            if (is_dir($target) && !rename($target, $backup)) {
                throw new RuntimeException('Unable to stage installed addon.');
            }
            if (!rename($replacement, $target)) {
                if (is_dir($backup)) rename($backup, $target);
                throw new RuntimeException('Unable to activate addon package.');
            }
            $frontendSource = $target.'/Frontend/dist';
            $activate = function () use ($target, $code, $manifest, $frontendSource, $webPrefix, $update): array {
                $synced = $this->resourceSynchronizer?->sync($target, $code) ?? ['schemas' => 0, 'configs' => 0, 'admin_resources' => 0];
                $this->lifecycle->transition($code, 'ready', ['version' => $manifest['version'], 'manifest' => $manifest]);
                $this->lifecycle->transition($code, 'active');
                if (is_dir($frontendSource)) {
                    $this->frontend->publish($webPrefix, $code, $frontendSource);
                } elseif ($update) {
                    $this->frontend->remove($webPrefix, $code);
                }
                return $synced;
            };
            $synced = null !== $this->database ? $this->database->transaction($activate) : $activate();
            if (is_dir($backup)) $this->deleteDirectory($backup);
            return ['code' => $code, 'version' => $manifest['version'], 'path' => $target, 'frontend' => is_dir($frontendSource), 'resources' => $synced];
        } catch (\Throwable $exception) {
            if (isset($target, $backup) && is_dir($backup)) {
                if (is_dir($target)) $this->deleteDirectory($target);
                @rename($backup, $target);
            } elseif (isset($target, $replacement) && is_dir($target) && !is_dir($replacement)) {
                $this->deleteDirectory($target);
            }
            if (isset($code) && ($lifecycleCaptured ?? false)) {
                $this->lifecycle->restore($code, $previousLifecycle ?? null);
                $restoredFrontend = isset($target) ? $target.'/Frontend/dist' : '';
                if (is_dir($restoredFrontend)) {
                    $this->frontend->publish($webPrefix, $code, $restoredFrontend);
                } else {
                    $this->frontend->remove($webPrefix, $code);
                }
            }
            throw $exception;
        } finally {
            if (is_dir($stage)) $this->deleteDirectory($stage);
            if (isset($lockHandle) && is_resource($lockHandle)) $this->locks?->release($lockHandle);
        }
    }

    /** @return array<string, mixed> */
    public function uninstall(string $code, string $webPrefix): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon code is invalid.', 'invalid_addon_code');
        }
        $lockHandle = $this->locks?->acquire($code);
        try {
            $target = $this->addonsPath.'/'.$code;
            if (!is_dir($target)) throw new AddonManifestException('The addon is not installed.', 'addon_not_installed');
            $previousLifecycle = $this->lifecycle->read($code);
            $backup = $target.'.uninstall-'.bin2hex(random_bytes(5));
            if (!rename($target, $backup)) throw new RuntimeException('Unable to stage addon for uninstall.');
            try {
                $dispose = function () use ($code, $webPrefix): void {
                    $this->lifecycle->transition($code, 'disposing');
                    $this->resourceSynchronizer?->deactivate($code);
                    $this->frontend->remove($webPrefix, $code);
                    $this->lifecycle->transition($code, 'disposed');
                };
                null !== $this->database ? $this->database->transaction($dispose) : $dispose();
                $this->deleteDirectory($backup);
            } catch (\Throwable $exception) {
                if (is_dir($backup)) @rename($backup, $target);
                $this->lifecycle->restore($code, $previousLifecycle);
                $frontendSource = $target.'/Frontend/dist';
                if (is_dir($frontendSource)) $this->frontend->publish($webPrefix, $code, $frontendSource);
                throw $exception;
            }
            return ['code' => $code, 'removed' => true, 'data_preserved' => true];
        } finally {
            if (isset($lockHandle) && is_resource($lockHandle)) $this->locks?->release($lockHandle);
        }
    }

    /** @return array<string, mixed> */
    public function syncFrontend(string $code, string $webPrefix): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon code is invalid.', 'invalid_addon_code');
        }
        $lockHandle = $this->locks?->acquire($code);
        try {
            $source = $this->addonsPath.'/'.$code.'/Frontend/dist';
            if (!is_dir($source)) throw new AddonManifestException('The addon frontend source is unavailable.', 'source_unavailable');
            $this->validateFrontendSource($this->addonsPath.'/'.$code, $source);
            return $this->frontend->publish($webPrefix, $code, $source);
        } finally {
            if (isset($lockHandle) && is_resource($lockHandle)) $this->locks?->release($lockHandle);
        }
    }

    private function validateFrontendSource(string $addonDirectory, string $source): void
    {
        $sourceRoot = realpath($source);
        if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
            throw new AddonManifestException('The addon frontend source is unavailable.', 'source_unavailable');
        }

        $descriptorPath = $addonDirectory.'/Frontend/frontend.json';
        if (!is_file($descriptorPath)) {
            return;
        }
        try {
            $descriptor = json_decode((string) file_get_contents($descriptorPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AddonManifestException('The addon frontend descriptor is invalid JSON.', 'frontend_descriptor_invalid');
        }
        $entries = is_array($descriptor) ? ($descriptor['entries'] ?? null) : null;
        if (!is_array($descriptor) || array_is_list($descriptor) || !is_array($entries) || !array_is_list($entries)) {
            throw new AddonManifestException('The addon frontend descriptor entries are invalid.', 'frontend_entries_invalid');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new AddonManifestException('The addon frontend entry is invalid.', 'frontend_entry_invalid');
            }
            $entryPath = (string) ($entry['entry'] ?? '');
            $sandbox = (string) ($entry['sandbox'] ?? '');
            $segments = explode('/', $entryPath);
            if ($entryPath === '' || str_starts_with($entryPath, '/') || str_contains($entryPath, '\\')
                || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $entryPath) !== 1
                || !in_array($sandbox, ['trusted', 'iframe'], true)) {
                throw new AddonManifestException('The addon frontend entry path is invalid.', 'frontend_entry_path_invalid');
            }
            $extension = strtolower((string) pathinfo($entryPath, PATHINFO_EXTENSION));
            if (($sandbox === 'trusted' && !in_array($extension, ['js', 'mjs'], true))
                || ($sandbox === 'iframe' && $extension !== 'html')
            ) {
                throw new AddonManifestException('The addon frontend entry type is invalid.', 'frontend_entry_type_invalid');
            }
            $publishedEntry = realpath($sourceRoot.'/'.$entryPath);
            if (!is_string($publishedEntry) || !str_starts_with($publishedEntry, $sourceRoot.DIRECTORY_SEPARATOR) || !is_file($publishedEntry) || !is_readable($publishedEntry)) {
                throw new AddonManifestException('The addon frontend entry is unavailable.', 'frontend_entry_unavailable', ['entry' => $entryPath]);
            }
        }
    }

    private function verifyArtifactChecksum(string $source, ?string $expectedSha256): void
    {
        if (null === $expectedSha256 || '' === $expectedSha256) {
            return;
        }

        $path = realpath($source);
        if (!is_string($path) || !is_file($path)) {
            throw new AddonManifestException(
                'The addon checksum can only verify a local package artifact.',
                'checksum_source_unavailable'
            );
        }

        $actualSha256 = hash_file('sha256', $path);
        if (!is_string($actualSha256) || !hash_equals(strtolower($expectedSha256), $actualSha256)) {
            throw new AddonManifestException('The addon artifact checksum is invalid.', 'checksum_mismatch');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}
