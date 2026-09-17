<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

/**
 * Applies the project trust policy after a manifest signature has been verified.
 * A valid signature alone is not sufficient to expose a trusted addon entry.
 */
final class AddonTrustPolicy
{
    /**
     * @param array<string, string> $trustedKeys key_id => PEM public key
     * @param array<int, string> $trustedPublishers
     */
    public function __construct(
        private array $trustedKeys = [],
        private array $trustedPublishers = [],
        private bool $allowLocal = false,
        private string $environment = 'production'
    ) {
    }

    /** @param array<string, mixed> $manifest */
    public function assertTrusted(array $manifest): void
    {
        $sandbox = (string) ($manifest['sandbox'] ?? 'iframe');
        if ('trusted' !== $sandbox) {
            return;
        }

        $publisher = (string) ($manifest['publisher'] ?? '');
        $keyId = (string) ($manifest['signature']['key_id'] ?? '');
        $publicKey = (string) ($manifest['signature']['public_key'] ?? '');

        if ($publisher === '') {
            throw new AddonManifestException('Trusted addons must declare a publisher.', 'trusted_metadata_missing');
        }

        if ($this->isLocalPublisher($publisher)) {
            if (!$this->allowLocal || !in_array($this->environment, ['local', 'testing'], true)) {
                throw new AddonManifestException('Local trusted addons are disabled in this environment.', 'trusted_publisher_denied');
            }
            if ($keyId === '' && $publicKey === '') {
                return;
            }
            if ($keyId === '' || $publicKey === '') {
                throw new AddonManifestException('Trusted addons must declare a complete signing key.', 'trusted_metadata_missing');
            }
            $this->assertKeyTrusted($keyId, $publicKey);

            return;
        }

        if ($keyId === '' || $publicKey === '') {
            throw new AddonManifestException('Trusted addons must declare publisher and signing key.', 'trusted_metadata_missing');
        }

        if ($this->trustedKeys === []) {
            throw new AddonManifestException('No trusted addon signing key is configured.', 'trusted_policy_unconfigured');
        }

        if ($this->trustedPublishers !== [] && !in_array($publisher, $this->trustedPublishers, true)) {
            throw new AddonManifestException('The addon publisher is not trusted.', 'trusted_publisher_denied', ['publisher' => $publisher]);
        }

        $this->assertKeyTrusted($keyId, $publicKey);
    }

    private function assertKeyTrusted(string $keyId, string $publicKey): void
    {
        if ($this->trustedKeys !== []) {
            $expected = $this->trustedKeys[$keyId] ?? null;
            if (!is_string($expected) || !hash_equals(trim($expected), trim($publicKey))) {
                throw new AddonManifestException('The addon signing key is not trusted.', 'trusted_key_denied', ['key_id' => $keyId]);
            }
        }
    }

    private function isLocalPublisher(string $publisher): bool
    {
        return str_starts_with(strtolower($publisher), 'local:') || strtolower($publisher) === 'local';
    }
}
