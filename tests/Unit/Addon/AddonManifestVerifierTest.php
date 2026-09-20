<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonManifestException;
use PTAdmin\Addon\AddonManifestVerifier;

final class AddonManifestVerifierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-manifest-'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            unlink($this->directory.'/'.$name);
        }
        @rmdir($this->directory);
    }

    public function testUnsignedManifestCanBeInspectedWithoutSignatureRequirement(): void
    {
        file_put_contents($this->directory.'/code.php', '<?php return 1;');
        file_put_contents($this->directory.'/manifest.json', json_encode([
            'code' => 'demo-addon', 'version' => '1.0.0', 'schema_protocol' => 1,
            'compatibility' => ['ptadmin/core' => '>=0.1.0 <1.0.0'],
        ], JSON_THROW_ON_ERROR));

        $manifest = (new AddonManifestVerifier('0.1.0', 1))->verify($this->directory, false);
        self::assertSame('demo-addon', $manifest['code']);
        self::assertSame('code.php', $manifest['verified_files'][0]['path']);
    }

    public function testSignatureIsRequiredForInstallVerification(): void
    {
        file_put_contents($this->directory.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $this->expectException(AddonManifestException::class);
        (new AddonManifestVerifier())->verify($this->directory);
    }

    public function testDeclaredFilesMustMatchPackageContents(): void
    {
        file_put_contents($this->directory.'/manifest.json', json_encode([
            'code' => 'demo-addon', 'version' => '1.0.0', 'files' => ['code.php'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/code.php', '<?php return 1;');
        file_put_contents($this->directory.'/unexpected.txt', 'unexpected');

        try {
            (new AddonManifestVerifier())->verify($this->directory, false);
            self::fail('Undeclared package files must be rejected.');
        } catch (AddonManifestException $exception) {
            self::assertSame('file_list_mismatch', $exception->errorCode);
        }
    }

    public function testSignatureCoversManifestMetadataAndCanonicalFileList(): void
    {
        file_put_contents($this->directory.'/code.php', '<?php return 1;');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $manifest = [
            'code' => 'demo-addon',
            'version' => '1.0.0',
            'schema_protocol' => 1,
            'compatibility' => ['ptadmin/core' => '>=0.1.0 <1.0.0'],
            'files' => ['code.php'],
            'signature' => ['algorithm' => 'RSA-SHA256', 'key_id' => 'test', 'public_key' => $details['key']],
        ];
        $files = [['path' => 'code.php', 'size' => filesize($this->directory.'/code.php'), 'sha256' => hash_file('sha256', $this->directory.'/code.php')]];
        $verifier = new AddonManifestVerifier();
        $canonical = $verifier->signingPayload($manifest, $files);
        openssl_sign($canonical, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $manifest['signature']['value'] = base64_encode($signature);
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        self::assertSame('demo-addon', $verifier->verify($this->directory)['code']);
        $manifest['compatibility']['ptadmin/core'] = '>=0.1.0 <2.0.0';
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            $verifier->verify($this->directory);
            self::fail('Modified manifest metadata must invalidate the signature.');
        } catch (AddonManifestException $exception) {
            self::assertSame('signature_invalid', $exception->errorCode);
        }
    }

    public function testManifestFingerprintUsesCanonicalPropertyOrder(): void
    {
        $verifier = new AddonManifestVerifier();
        $first = [
            'code' => 'demo-addon',
            'version' => '1.0.0',
            'compatibility' => ['ptadmin/core' => '>=0.1.0'],
            'verified_files' => [['path' => 'code.php', 'size' => 1, 'sha256' => 'abc']],
        ];
        $second = [
            'verified_files' => [['sha256' => 'abc', 'size' => 1, 'path' => 'code.php']],
            'compatibility' => ['ptadmin/core' => '>=0.1.0'],
            'version' => '1.0.0',
            'code' => 'demo-addon',
        ];

        self::assertSame($verifier->manifestSha256($first), $verifier->manifestSha256($second));
        self::assertStringStartsWith('sha256:', $verifier->manifestSha256($first));
    }
}
