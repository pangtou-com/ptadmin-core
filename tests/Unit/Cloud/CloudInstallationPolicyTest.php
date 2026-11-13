<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Cloud\CloudInstallationPolicy;

final class CloudInstallationPolicyTest extends TestCase
{
    private Container $previousContainer;
    private string $directory;
    private ManifestVerifier $verifier;
    private CloudInstallationPolicy $policy;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->directory = sys_get_temp_dir().'/ptadmin-cloud-update-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/cms', 0700, true);
        $container = new Container();
        $container->instance('config', new Repository([
            'ptadmin' => ['backend_version' => '0.12.0', 'addon' => ['path' => $this->directory]],
        ]));
        Container::setInstance($container);
        $this->verifier = new ManifestVerifier('0.12.0');
        $this->policy = new CloudInstallationPolicy($this->verifier);
        $this->writeManifest();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        Container::setInstance($this->previousContainer);
    }

    public function testCoreUpgradeAllowsReplacingAnIncompatibleInstalledAddon(): void
    {
        self::assertSame([
            'local_version' => '0.2.2', 'install_operation' => 'update', 'install_reason' => '',
        ], $this->policy->describe('cms', 'addon', '0.3.0', '>=0.12.0 <0.13.0'));

        $this->expectException(AddonException::class);
        $this->expectExceptionMessage('incompatible');
        $this->verifier->verifyRuntime($this->directory.'/cms');
    }

    public function testNewReleaseMustStillSupportCurrentCore(): void
    {
        $result = $this->policy->describe('cms', 'addon', '0.4.0', '>=0.13.0 <0.14.0');
        self::assertSame('0.2.2', $result['local_version']);
        self::assertNull($result['install_operation']);
        self::assertStringContainsString('当前 Core 版本不满足', $result['install_reason']);
    }

    public function testIncompatibleInstalledAddonCannotBeDowngraded(): void
    {
        $result = $this->policy->describe('cms', 'addon', '0.2.1', '>=0.12.0 <0.13.0');
        self::assertSame('0.2.2', $result['local_version']);
        self::assertNull($result['install_operation']);
        self::assertStringContainsString('不能在线降级', $result['install_reason']);
    }

    public function testInvalidInstalledCompatibilityDeclarationStillBlocksUpdate(): void
    {
        $this->writeManifest('broken-constraint');
        $this->assertInvalidLocalManifest();
    }

    public function testInvalidInstalledComposerMappingStillBlocksUpdate(): void
    {
        mkdir($this->directory.'/cms/src');
        file_put_contents($this->directory.'/cms/composer.json', json_encode([
            'autoload' => ['psr-4' => ['PTAdmin\\Addons\\Cms\\' => 'wrong/']],
        ], JSON_THROW_ON_ERROR));
        $this->assertInvalidLocalManifest();
    }

    public function testMalformedInstalledManifestStillBlocksUpdate(): void
    {
        file_put_contents($this->directory.'/cms/manifest.json', '{');
        $this->assertInvalidLocalManifest();
    }

    private function assertInvalidLocalManifest(): void
    {
        $result = $this->policy->describe('cms', 'addon', '0.3.0', '>=0.12.0 <0.13.0');
        self::assertNull($result['install_operation']);
        self::assertStringContainsString('本地插件清单无法验证', $result['install_reason']);
    }

    private function writeManifest(string $constraint = '>=0.11.0 <0.12.0'): void
    {
        file_put_contents($this->directory.'/cms/manifest.json', json_encode([
            'code' => 'cms', 'version' => '0.2.2', 'namespace' => 'PTAdmin\\Addons\\Cms',
            'compatibility' => ['ptadmin/core' => $constraint],
        ], JSON_THROW_ON_ERROR));
    }
}
