<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use RuntimeException;

final class AddonStateManager
{
    public function __construct(
        private string $addonsPath,
        private AddonManifestVerifier $verifier,
        private AddonLifecycleStore $lifecycle,
        private AddonLock $locks,
        private bool $requireSignature = true,
        private ?AddonResourceSynchronizerInterface $resourceSynchronizer = null,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?AddonRuntimeLifecycleValidator $runtimeValidator = null,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
    }

    /** @return array<string,mixed> */
    public function disable(string $code): array
    {
        return $this->change($code, false);
    }

    /** @return array<string,mixed> */
    public function enable(string $code): array
    {
        return $this->change($code, true);
    }

    /** @return array<string,mixed> */
    private function change(string $code, bool $enabled): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon code is invalid.', 'invalid_addon_code');
        }
        $directory = $this->addonsPath.'/'.$code;
        if (!is_dir($directory)) {
            throw new AddonManifestException('The addon is not installed.', 'addon_not_installed');
        }
        $lock = $this->locks->acquire($code);
        try {
            $manifest = $this->verifier->verify($directory, $this->requireSignature);
            $this->runtimeCache?->clear();
            $current = $this->lifecycle->read($code);
            $runtimeValidationStarted = false;
            if (!$enabled) {
                $this->resourceSynchronizer?->deactivate($code);
                $state = (string) ($current['state'] ?? 'discovered');
                if ($current === null) {
                    $this->lifecycle->transition($code, 'discovered');
                    $state = 'discovered';
                }
                if ($state === 'discovered') {
                    $this->lifecycle->transition($code, 'validating');
                    $state = 'validating';
                }
                if ($state === 'validating') {
                    $this->lifecycle->transition($code, 'loading');
                    $state = 'loading';
                }
                if ($state === 'loading') {
                    $this->lifecycle->transition($code, 'ready');
                }
                $result = $this->lifecycle->transition($code, 'inactive', [
                    'disabled_by_user' => true,
                    'runtime_error' => null,
                    'version' => $manifest['version'],
                    'manifest' => $manifest,
                ]);
                $runtimeValidationStarted = true;
                $this->runtimeValidator?->assertDeactivated($code);
            } else {
                $this->resourceSynchronizer?->sync($directory, $code);
                $state = (string) ($current['state'] ?? 'discovered');
                if ($current === null || $state === 'disposed') {
                    if ($state === 'disposed') {
                        $this->lifecycle->transition($code, 'discovered');
                    } else {
                        $this->lifecycle->transition($code, 'discovered');
                    }
                    $state = 'discovered';
                }
                if ($state === 'discovered') {
                    $this->lifecycle->transition($code, 'validating');
                    $state = 'validating';
                }
                if ($state === 'validating') {
                    $this->lifecycle->transition($code, 'loading');
                    $state = 'loading';
                }
                if ($state === 'loading') {
                    $this->lifecycle->transition($code, 'ready');
                }
                $result = $this->lifecycle->transition($code, 'active', [
                    'disabled_by_user' => false,
                    'runtime_error' => null,
                    'version' => $manifest['version'],
                    'manifest' => $manifest,
                ]);
                $runtimeValidationStarted = true;
                $this->runtimeValidator?->assertActivated($directory, $code, $manifest);
            }
            return ['code' => $code, 'enabled' => $enabled, 'state' => $result['state'], 'version' => $manifest['version']];
        } catch (\Throwable $exception) {
            if (($runtimeValidationStarted ?? false) && $this->runtimeValidator !== null) {
                try {
                    if ($enabled) {
                        $this->resourceSynchronizer?->deactivate($code);
                    } else {
                        $this->resourceSynchronizer?->sync($directory, $code);
                    }
                } catch (\Throwable) {
                    // Keep the original Runtime diagnostic; the next lifecycle operation can retry synchronization.
                }
                try {
                    $this->lifecycle->restore($code, $current ?? null);
                } catch (\Throwable) {
                    // Preserve the original Runtime diagnostic.
                }
            }
            throw $exception;
        } finally {
            $this->locks->release($lock);
        }
    }
}
