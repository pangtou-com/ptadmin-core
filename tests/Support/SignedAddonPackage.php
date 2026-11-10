<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Support;

use PTAdmin\Addon\Manifest\ManifestVerifier;

/** 用真实 RSA 签名生成隔离测试包，不依赖开发宿主身份或密钥。 */
final class SignedAddonPackage
{
    public static function sign(string $directory, ?\OpenSSLAsymmetricKey $key = null): array
    {
        $key ??= openssl_pkey_new(['private_key_bits' => 2048]);
        $verifier = new ManifestVerifier();
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['publisher'] = 'test.publisher';
        $manifest['sandbox'] = 'trusted';
        $manifest['signature'] = ['algorithm' => 'RSA-SHA256', 'key_id' => 'test-key', 'public_key' => openssl_pkey_get_details($key)['key']];
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $files = $verifier->verify($directory, false)['verified_files'];
        openssl_sign($verifier->signingPayload($manifest, $files), $signature, $key, OPENSSL_ALGO_SHA256);
        $manifest['signature']['value'] = base64_encode($signature);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        return $manifest;
    }
}
