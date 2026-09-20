<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Manifest;

use PTAdmin\Addon\Exception\AddonException;

/**
 * 在包签名验证成功后执行当前部署的 trusted 信任策略。
 *
 * 签名有效只表示文件与签名者一致，不表示该签名者被本系统信任；trusted 前端入口还必须满足发布者白名单、
 * key_id 与配置公钥匹配。未声明 trusted sandbox 的插件不会进入这条额外信任判断。
 */
final class TrustPolicy
{
    /**
     * @param array<string, string> $trustedKeys key_id 到 PEM 公钥的部署级信任映射
     * @param array<int, string> $trustedPublishers 允许提供 trusted 插件的发布者标识
     */
    public function __construct(
        private array $trustedKeys = [],
        private array $trustedPublishers = [],
        private bool $allowLocal = false,
        private string $environment = 'production'
    ) {
    }

    /**
     * 断言 manifest 满足 trusted 发布策略；local 发布者只能在 local/testing 环境显式放行。
     *
     * @param array<string, mixed> $manifest 已完成签名验证的 manifest
     */
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
            throw new AddonException('Trusted addons must declare a publisher.', 'trusted_metadata_missing');
        }

        if ($this->isLocalPublisher($publisher)) {
            if (!$this->allowLocal || !in_array($this->environment, ['local', 'testing'], true)) {
                throw new AddonException('Local trusted addons are disabled in this environment.', 'trusted_publisher_denied');
            }
            if ($keyId === '' && $publicKey === '') {
                return;
            }
            if ($keyId === '' || $publicKey === '') {
                throw new AddonException('Trusted addons must declare a complete signing key.', 'trusted_metadata_missing');
            }
            $this->assertKeyTrusted($keyId, $publicKey);

            return;
        }

        if ($keyId === '' || $publicKey === '') {
            throw new AddonException('Trusted addons must declare publisher and signing key.', 'trusted_metadata_missing');
        }

        if ($this->trustedKeys === []) {
            throw new AddonException('No trusted addon signing key is configured.', 'trusted_policy_unconfigured');
        }

        if ($this->trustedPublishers !== [] && !in_array($publisher, $this->trustedPublishers, true)) {
            throw new AddonException('The addon publisher is not trusted.', 'trusted_publisher_denied', ['publisher' => $publisher]);
        }

        $this->assertKeyTrusted($keyId, $publicKey);
    }

    private function assertKeyTrusted(string $keyId, string $publicKey): void
    {
        if ($this->trustedKeys !== []) {
            $expected = $this->trustedKeys[$keyId] ?? null;
            if (!is_string($expected) || !hash_equals(trim($expected), trim($publicKey))) {
                throw new AddonException('The addon signing key is not trusted.', 'trusted_key_denied', ['key_id' => $keyId]);
            }
        }
    }

    private function isLocalPublisher(string $publisher): bool
    {
        return str_starts_with(strtolower($publisher), 'local:') || strtolower($publisher) === 'local';
    }
}
