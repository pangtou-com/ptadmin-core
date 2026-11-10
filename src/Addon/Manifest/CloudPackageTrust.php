<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Manifest;

use PTAdmin\Addon\Exception\AddonException;

/** 云交付服务验证下载后创建的单包信任上下文，不从请求或插件清单恢复。 */
final readonly class CloudPackageTrust
{
    public function __construct(
        public string $code,
        public string $version,
        public string $sha256,
    ) {
        if ($code === '' || $version === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new AddonException('云市场安装包的交付标识无效。', 'cloud_package_identity_invalid');
        }
    }

    /** 安装前重新检查云端摘要，阻止把信任上下文用于另一个本地包或目录。 */
    public function assertArtifact(string $source): void
    {
        if (!is_file($source) || !hash_equals($this->sha256, (string) hash_file('sha256', $source))) {
            throw new AddonException('云市场安装包摘要不匹配，请重新下载。', 'checksum_mismatch');
        }
    }

    /** 签名通过后复核交付对象，不能仅凭同一发布者或公钥扩大信任范围。 */
    public function assertManifest(array $manifest): void
    {
        if (($manifest['code'] ?? null) !== $this->code || ($manifest['version'] ?? null) !== $this->version) {
            throw new AddonException('插件编码或版本与云市场交付不一致。', 'cloud_package_identity_mismatch');
        }
        if (($manifest['sandbox'] ?? 'iframe') === 'trusted'
            && (!is_string($manifest['publisher'] ?? null) || $manifest['publisher'] === ''
                || !is_string($manifest['signature']['key_id'] ?? null) || $manifest['signature']['key_id'] === '')) {
            throw new AddonException('云市场插件必须声明发布者和签名密钥标识。', 'trusted_metadata_missing');
        }
    }

    /** 仅在完整签名验证后保存，随安装器生命周期快照一起提交或回滚。 */
    public function record(ManifestVerifier $verifier, array $manifest): array
    {
        return [
            'source' => 'cloud_market',
            'code' => $this->code,
            'version' => $this->version,
            'artifact_sha256' => $this->sha256,
            'manifest_sha256' => $verifier->manifestSha256($manifest),
            'metadata_sha256' => self::metadataSha256($verifier, $manifest),
        ];
    }

    /** 只接受宿主私有生命周期记录；签名值和元数据变化均使记录失效。 */
    public static function matches(ManifestVerifier $verifier, array $manifest, ?array $record): bool
    {
        return ($record['source'] ?? null) === 'cloud_market'
            && ($record['code'] ?? null) === ($manifest['code'] ?? null)
            && ($record['version'] ?? null) === ($manifest['version'] ?? null)
            && is_string($record['artifact_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $record['artifact_sha256']) === 1
            && is_string($record['manifest_sha256'] ?? null)
            && preg_match('/^sha256:[a-f0-9]{64}$/D', $record['manifest_sha256']) === 1
            && is_string($record['metadata_sha256'] ?? null)
            && hash_equals($record['metadata_sha256'], self::metadataSha256($verifier, $manifest));
    }

    private static function metadataSha256(ManifestVerifier $verifier, array $manifest): string
    {
        // Runtime 不扫描插件文件；签名值绑定安装时验证过的完整文件清单。
        return hash('sha256', $verifier->signingPayload($manifest, [])."\n".($manifest['signature']['value'] ?? ''));
    }
}
