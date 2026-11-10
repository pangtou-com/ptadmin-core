<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Lifecycle;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\CloudPackageTrust;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Manifest\TrustPolicy;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Core\Tests\Support\SignedAddonPackage;
use RuntimeException;
use ZipArchive;

final class CloudPackageTrustTest extends TestCase
{
    private string $root;
    private LifecycleStore $state;
    private ManifestVerifier $verifier;
    private TrustPolicy $policy;
    private \OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-cloud-trust-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->state = new LifecycleStore($this->root.'/state');
        $this->policy = new TrustPolicy();
        $this->verifier = new ManifestVerifier('0.1.0', 1, $this->policy);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testCloudPackageInstallsWithoutConfiguredKeysAndFrontendSurvivesSetup(): void
    {
        $package = $this->package('1.0.0');
        $this->installer()->install($package, 'console', cloudTrust: $this->delivery($package));
        $record = $this->state->read('demo-addon')['cloud_delivery'];
        self::assertSame(hash_file('sha256', $package), $record['artifact_sha256']);
        self::assertSame('cloud_market', $record['source']);
        $this->assertFrontendTrusted();
        $this->installer()->setup('demo-addon', 'console');
        $this->assertFrontendTrusted();

        // 云包安装不改变公钥策略，连同一签名者的同一包也不能从本地入口借用信任。
        try {
            $this->installer()->install($package, 'console', true);
            self::fail('本地更新必须检查部署公钥。');
        } catch (AddonException $exception) {
            self::assertSame('trusted_policy_unconfigured', $exception->errorCode);
        }
        self::assertSame($record, $this->state->read('demo-addon')['cloud_delivery']);
    }

    public function testLocalUploadStillRequiresTrustedKeyAndCannotClaimCloudOrigin(): void
    {
        $package = $this->package('1.0.0');
        try {
            $this->installer()->install($package, 'console');
            self::fail('本地上传不可自动受信。');
        } catch (AddonException $exception) {
            self::assertSame('trusted_policy_unconfigured', $exception->errorCode);
        }
        self::assertNull($this->state->read('demo-addon'));
        self::assertDirectoryDoesNotExist($this->root.'/addons/demo-addon');
    }

    #[DataProvider('invalidDeliveries')]
    public function testCloudDeliveryRejectsInvalidPackages(string $case, string $expected): void
    {
        $package = $this->package('1.0.0');
        $delivery = $this->delivery($package);
        if ($case === 'checksum') {
            file_put_contents($package, 'changed', FILE_APPEND);
        } elseif ($case === 'version') {
            $delivery = $this->delivery($package, '2.0.0');
        } elseif ($case === 'code') {
            $delivery = new CloudPackageTrust('other-addon', '1.0.0', hash_file('sha256', $package));
        } else {
            $zip = new ZipArchive();
            $zip->open($package);
            if ($case === 'file') {
                $zip->addFromString('frontend/dist/entry.js', 'tampered');
            } else {
                $manifest = json_decode($zip->getFromName('manifest.json'), true);
                if ($case === 'unsigned') {
                    unset($manifest['signature']);
                } else {
                    $manifest['title'] = 'tampered';
                }
                $zip->addFromString('manifest.json', json_encode($manifest));
            }
            $zip->close();
            $delivery = $this->delivery($package);
        }
        try {
            // 即使宿主关闭本地签名要求，云市场信任仍须完整验证签名。
            $this->installer(requireSignature: false)->install($package, 'console', cloudTrust: $delivery);
            self::fail('无效云包必须被拒绝。');
        } catch (AddonException $exception) {
            self::assertSame($expected, $exception->errorCode);
        }
        self::assertNull($this->state->read('demo-addon'));
        self::assertDirectoryDoesNotExist($this->root.'/addons/demo-addon');
    }

    public static function invalidDeliveries(): array
    {
        return [
            ['checksum', 'checksum_mismatch'],
            ['version', 'cloud_package_identity_mismatch'],
            ['code', 'cloud_package_identity_mismatch'],
            ['file', 'signature_invalid'],
            ['metadata', 'signature_invalid'],
            ['unsigned', 'signature_missing'],
        ];
    }

    public function testFailedUpdateRestoresOldReceiptAndSuccessfulUpdateReplacesIt(): void
    {
        $first = $this->package('1.0.0');
        $this->installer()->install($first, 'console', cloudTrust: $this->delivery($first));
        $before = $this->state->read('demo-addon');
        $second = $this->package('2.0.0');
        try {
            $this->installer()->install($second, 'console', true, onProgress: static function (string $stage): void {
                if ($stage === 'publishing') {
                    throw new RuntimeException('模拟发布失败');
                }
            }, cloudTrust: $this->delivery($second, '2.0.0'));
            self::fail('更新应失败。');
        } catch (RuntimeException $exception) {
            self::assertSame('模拟发布失败', $exception->getMessage());
        }
        self::assertSame($before, $this->state->read('demo-addon'));
        $this->assertFrontendTrusted();
        $this->installer()->install($second, 'console', true, cloudTrust: $this->delivery($second, '2.0.0'));
        self::assertSame('2.0.0', $this->state->read('demo-addon')['cloud_delivery']['version']);
        $this->assertFrontendTrusted();
    }

    public function testFailedFirstInstallLeavesNoTrustAndLocalUpdateClearsCloudTrust(): void
    {
        $package = $this->package('1.0.0');
        try {
            $this->installer()->install($package, 'console', onProgress: static function (string $stage): void {
                if ($stage === 'publishing') {
                    throw new RuntimeException('模拟发布失败');
                }
            }, cloudTrust: $this->delivery($package));
            self::fail('首次安装应失败。');
        } catch (RuntimeException $exception) {
            self::assertSame('模拟发布失败', $exception->getMessage());
        }
        self::assertNull($this->state->read('demo-addon'));
        $this->installer()->install($package, 'console', cloudTrust: $this->delivery($package));
        $this->policy = new TrustPolicy(['test-key' => openssl_pkey_get_details($this->key)['key']]);
        $this->verifier = new ManifestVerifier('0.1.0', 1, $this->policy);
        $this->installer()->install($this->package('2.0.0'), 'console', true);
        self::assertNull($this->state->read('demo-addon')['cloud_delivery']);
        $this->assertFrontendTrusted();
    }

    public function testChangedManifestCannotReuseInstalledCloudTrust(): void
    {
        $package = $this->package('1.0.0');
        $this->installer()->install($package, 'console', cloudTrust: $this->delivery($package));
        $path = $this->root.'/addons/demo-addon/manifest.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['signature']['value'] = base64_encode('different signature');
        file_put_contents($path, json_encode($manifest));
        (new AddonCatalog($this->root.'/addons', $this->verifier, $this->state))->rebuild();
        $resolved = $this->frontend();
        self::assertSame([], $resolved['plugins']);
        self::assertSame('trusted_policy_unconfigured', $resolved['errors'][0]['error_code']);
    }

    private function installer(bool $requireSignature = true): Installer
    {
        return new Installer($this->verifier, new PackageExtractor(), $this->state,
            new FrontendPublisher($this->root.'/public'), $this->root.'/addons', $this->root.'/staging', $requireSignature);
    }

    private function delivery(string $package, string $version = '1.0.0'): CloudPackageTrust
    {
        return new CloudPackageTrust('demo-addon', $version, hash_file('sha256', $package));
    }

    private function package(string $version): string
    {
        $directory = $this->root.'/source-'.$version;
        mkdir($directory.'/frontend/dist', 0700, true);
        file_put_contents($directory.'/manifest.json', json_encode(['code' => 'demo-addon', 'version' => $version]));
        file_put_contents($directory.'/frontend/dist/entry.js', 'export default {}');
        file_put_contents($directory.'/frontend/frontend.json', json_encode([
            'host_api' => 'v1', 'runtime_profile' => 'admin-v1',
            'entries' => [['key' => 'demo-addon.page', 'title' => '测试页面', 'entry' => 'entry.js', 'sandbox' => 'trusted', 'capabilities' => []]],
        ]));
        $manifest = SignedAddonPackage::sign($directory, $this->key);
        // 即使包主动附带可信来源信息，本地入口也不得采信。
        $manifest['cloud_delivery'] = ['source' => 'cloud_market'];
        file_put_contents($directory.'/manifest.json', json_encode($manifest));
        SignedAddonPackage::sign($directory, $this->key);
        $path = $this->root.'/package-'.$version.'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach (['manifest.json', 'frontend/frontend.json', 'frontend/dist/entry.js'] as $file) {
            $zip->addFile($directory.'/'.$file, $file);
        }
        $zip->close();
        return $path;
    }

    private function assertFrontendTrusted(): void
    {
        $resolved = $this->frontend();
        self::assertSame([], $resolved['errors']);
        self::assertCount(1, $resolved['plugins']);
        self::assertTrue($resolved['plugins'][0]['entries'][0]['trust']['verified']);
        self::assertStringStartsWith('sha256:', $resolved['plugins'][0]['entries'][0]['trust']['manifest_sha256']);
    }

    private function frontend(): array
    {
        $registry = new FrontendRegistry(
            new AddonCatalog($this->root.'/addons', $this->verifier, $this->state),
            $this->verifier, $this->policy, $this->state, new FrontendDevelopmentStore('production'),
            $this->root.'/addons', $this->root.'/public', 'console', 'ptadmin', ['v1'], ['admin-v1'], [],
        );
        return $registry->resolve([['key' => 'demo-addon.page', 'owner_type' => 'addon', 'owner_code' => 'demo-addon']]);
    }
}
