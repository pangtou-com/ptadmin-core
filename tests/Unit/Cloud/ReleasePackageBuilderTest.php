<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\ReleasePackageBuilder;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Theme\ThemeManifestValidator;
use ZipArchive;

final class ReleasePackageBuilderTest extends TestCase
{
    private string $root;
    private Container $previous;
    private ReleasePackageBuilder $builder;
    private ApplicationIdentity $identity;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pt-publish-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/source', 0700, true);
        $this->previous = Container::getInstance();
        $app = new Application($this->root);
        $app->useStoragePath($this->root.'/runtime');
        Container::setInstance($app);
        $this->identity = new ApplicationIdentity($this->root.'/identity.json');
        $this->identity->createForInstall();
        $this->builder = new ReleasePackageBuilder($this->identity, new Filesystem());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testAddonBuildSignsActualFilesExcludesDevelopmentAndLeavesSourceUntouched(): void
    {
        $manifest = ['code' => 'demo', 'version' => '1.0.0', 'sandbox' => 'iframe'];
        $this->write('manifest.json', json_encode($manifest));
        $this->write('src/service.php', '<?php // release');
        $frontend = ['development' => ['entry_url' => 'http://localhost:5173/src.ts'], 'entries' => [['entry' => 'frontend.mjs', 'sandbox' => 'trusted']]];
        $this->write('frontend/frontend.json', json_encode($frontend));
        $this->write('frontend/dist/frontend.mjs', 'export default {}');
        $this->write('frontend/dist/.vite/manifest.json', '{}');
        foreach (['.env', '.git/config', 'private.pem', 'node_modules/pkg/file.js', 'tests/test.php', 'frontend/src/source.ts', 'frontend/dist/frontend.mjs.map'] as $path) {
            $this->write($path, 'must not ship');
        }
        $path = $this->builder->withPackage('addon', $this->root.'/source', '1.2.0', function (array $package): string {
            self::assertSame('1.2.0', $package['version']);
            self::assertSame(5, $package['files']);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($package['path']));
            self::assertFalse($zip->getFromName('.env'));
            self::assertFalse($zip->getFromName('private.pem'));
            self::assertNotFalse($zip->getFromName('frontend/dist/.vite/manifest.json'));
            self::assertArrayNotHasKey('development', json_decode($zip->getFromName('frontend/frontend.json'), true));
            $zip->extractTo($this->root.'/verified');
            $zip->close();
            $verified = (new ManifestVerifier())->verify($this->root.'/verified', true, false);
            self::assertSame($this->identity->load()['public_key'], $verified['signature']['public_key']);
            self::assertSame('1.2.0', $verified['version']);
            return $package['path'];
        }, true);
        self::assertFileDoesNotExist($path);
        self::assertSame($manifest, json_decode(file_get_contents($this->root.'/source/manifest.json'), true));
        self::assertSame($frontend, json_decode(file_get_contents($this->root.'/source/frontend/frontend.json'), true));
    }

    public function testTemplateBuildUsesManifestVersionAndCleansAfterConsumerFailure(): void
    {
        $this->write('theme.json', json_encode(['code' => 'theme-demo', 'name' => '模板', 'version' => '1.0.0']));
        $this->write('index.blade.php', 'Template');
        try {
            $this->builder->withPackage('template', $this->root.'/source', null, function (array $package): void {
                $zip = new ZipArchive();
                self::assertTrue($zip->open($package['path']));
                $zip->extractTo($this->root.'/verified');
                $zip->close();
                (new ThemeManifestValidator())->validateDirectory($this->root.'/verified', 'theme-demo', '1.0.0');
                throw new \RuntimeException('平台拒绝');
            });
            self::fail('Consumer error must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('平台拒绝', $exception->getMessage());
        }
        self::assertSame([], glob($this->root.'/runtime/app/ptadmin/releases/.staging-*'));
    }

    public function testSymlinksAndOversizedSourceFilesAreRejectedBeforeConsumption(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        file_put_contents($this->root.'/secret', 'private');
        symlink($this->root.'/secret', $this->root.'/source/link.txt');
        $this->assertInvalidPackage('符号链接');
        unlink($this->root.'/source/link.txt');
        $file = fopen($this->root.'/source/large.txt', 'w');
        ftruncate($file, 17 * 1024 * 1024);
        fclose($file);
        $this->assertInvalidPackage('超出限制');
    }

    public function testMissingFrontendBuildAndInvalidVersionsCannotBePublished(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        $this->write('frontend/frontend.json', json_encode(['entries' => [['entry' => 'frontend.mjs', 'sandbox' => 'trusted']]]));
        $this->assertInvalidPackage('前端入口产物缺失');
        $this->assertInvalidPackage('版本号无效', '../bad');
        self::assertSame([], glob($this->root.'/runtime/app/ptadmin/releases/.staging-*'));
    }

    public function testFrontendIsBuiltInSnapshotAndBothPackagesStayBoundAndInstallable(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        $this->write('frontend/frontend.json', json_encode(['development' => ['entry_url' => 'http://127.0.0.1:5173/src.ts'], 'entries' => [['entry' => 'frontend.mjs', 'sandbox' => 'trusted']]]));
        $this->write('frontend/src/frontend.ts', 'export default {version: "new"}');
        $this->write('frontend/dist/frontend.mjs', 'old build');
        $this->write('frontend/build.php', '<?php mkdir("dist"); file_put_contents("dist/frontend.mjs", file_get_contents("src/frontend.ts"));');
        $this->write('ptadmin.release.json', json_encode(['build' => ['directory' => 'frontend', 'command' => [PHP_BINARY, 'build.php'], 'output' => 'frontend/dist']]));
        $this->builder->withPackage('addon', $this->root.'/source', '1.1.0', function (array $package): void {
            $source = new ZipArchive();
            self::assertTrue($source->open($package['source']['path']));
            self::assertSame('export default {version: "new"}', $source->getFromName('frontend/src/frontend.ts'));
            self::assertFalse($source->getFromName('frontend/dist/frontend.mjs'));
            self::assertSame('1.1.0', json_decode($source->getFromName('manifest.json'), true)['version']);
            $source->close();
            $zip = new ZipArchive();
            self::assertTrue($zip->open($package['path']));
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            self::assertSame(hash_file('sha256', $package['source']['path']), $manifest['release']['source_sha256']);
            self::assertFalse($zip->getFromName('frontend/src/frontend.ts'));
            self::assertFalse($zip->getFromName('frontend/build.php'));
            self::assertSame('export default {version: "new"}', $zip->getFromName('frontend/dist/frontend.mjs'));
            $zip->close();
            $installer = new \PTAdmin\Addon\Lifecycle\Installer(new ManifestVerifier(), new \PTAdmin\Addon\Package\PackageExtractor(),
                new \PTAdmin\Addon\Lifecycle\LifecycleStore($this->root.'/state'), new \PTAdmin\Addon\Frontend\FrontendPublisher($this->root.'/public'),
                $this->root.'/addons', $this->root.'/install-staging');
            self::assertTrue($installer->install($package['path'], 'admintest')['frontend']);
            self::assertSame('export default {version: "new"}', file_get_contents($this->root.'/public/admintest/plugins/demo/frontend.mjs'));
            try {
                $installer->install($package['source']['path'], 'admintest');
                self::fail('Source archives must not install.');
            } catch (\PTAdmin\Addon\Exception\AddonException $exception) {
                self::assertSame('source_package_not_installable', $exception->errorCode);
            }
        });
        self::assertSame('old build', file_get_contents($this->root.'/source/frontend/dist/frontend.mjs'));
        self::assertSame('1.0.0', json_decode(file_get_contents($this->root.'/source/manifest.json'), true)['version']);
    }

    public function testBuildFailureDoesNotConsumeStaleDistOrMutateLocalFiles(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        $this->write('frontend/frontend.json', json_encode(['entries' => [['entry' => 'entry.js', 'sandbox' => 'trusted']]]));
        $this->write('frontend/dist/entry.js', 'stale');
        $this->write('frontend/build.php', '<?php exit(7);');
        $this->write('ptadmin.release.json', json_encode(['build' => ['directory' => 'frontend', 'command' => [PHP_BINARY, 'build.php'], 'output' => 'frontend/dist']]));
        $this->assertInvalidPackage('前端构建失败');
        self::assertSame('stale', file_get_contents($this->root.'/source/frontend/dist/entry.js'));
        self::assertSame([], glob($this->root.'/runtime/app/ptadmin/releases/.staging-*'));
    }

    public function testIgnoreRulesSeparateSourceAndInstallAndCannotRemoveRequiredFiles(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        $this->write('src/Service.php', '<?php');
        $this->write('docs/guide.md', 'development guide');
        $this->write('resources/vendor/icon.svg', '<svg/>');
        $this->write('.env.production', 'secret');
        $this->write('ptadmin.release.json', json_encode(['install' => ['exclude' => ['docs/**']]]));
        $this->builder->withPackage('addon', $this->root.'/source', null, function (array $package): void {
            self::assertNotContains('docs/guide.md', $package['paths']);
            self::assertContains('docs/guide.md', $package['source']['paths']);
            self::assertContains('resources/vendor/icon.svg', $package['paths']);
            self::assertNotContains('.env.production', $package['source']['paths']);
            self::assertContains('src/Service.php', $package['paths']);
        });
        $this->write('ptadmin.release.json', json_encode(['install' => ['exclude' => ['manifest.json']]]));
        $this->assertInvalidPackage('不能忽略商品清单');
        $this->write('ptadmin.release.json', json_encode(['source' => ['exclude' => ['manifest.json']]]));
        $this->assertInvalidPackage('源码包不能忽略');
    }

    public function testTemplatePackageInstallsUnderVersionDirectoryAndSourceCannotInstall(): void
    {
        $this->write('theme.json', json_encode(['code' => 'theme-demo', 'version' => '1.0.0', 'name' => '模板']));
        $this->write('index.blade.php', 'template');
        $this->write('assets/site.css', 'body{}');
        $this->builder->withPackage('template', $this->root.'/source', '1.1.0', function (array $package): void {
            $installer = new \PTAdmin\Core\Theme\ThemeInstaller(new \PTAdmin\Core\Theme\ThemePackageExtractor(),
                new ThemeManifestValidator(), $this->root.'/templates', $this->root.'/theme-staging');
            $result = $installer->install($package['path'], 'theme-demo', '1.1.0');
            self::assertSame($this->root.'/templates/theme-demo@1.1.0', $result['path']);
            self::assertFileExists($result['path'].'/assets/site.css');
            self::assertContains('assets/site.css', $package['source']['paths']);
            try {
                $installer->install($package['source']['path']);
                self::fail('Source archives must not install.');
            } catch (\PTAdmin\Core\Theme\ThemeException $exception) {
                self::assertSame('source_package_not_installable', $exception->errorCode);
            }
        });
    }

    public function testTemplateUsesDeclaredBuildToolAndKeepsStaticInputsInSource(): void
    {
        $this->write('theme.json', json_encode(['code' => 'theme-demo', 'version' => '1.0.0', 'name' => '模板']));
        $this->write('index.blade.php', 'template');
        $this->write('assets/logo.svg', '<svg/>');
        $this->write('frontend/src/style.scss', 'body{color:red}');
        $this->write('frontend/build.php', '<?php mkdir("../assets/build", 0700, true); file_put_contents("../assets/build/site.css", file_get_contents("src/style.scss"));');
        $this->write('ptadmin.release.json', json_encode(['build' => ['directory' => 'frontend', 'command' => [PHP_BINARY, 'build.php'], 'output' => 'assets/build']]));
        $this->builder->withPackage('template', $this->root.'/source', null, function (array $package): void {
            self::assertContains('assets/build/site.css', $package['paths']);
            self::assertContains('assets/logo.svg', $package['paths']);
            self::assertNotContains('frontend/src/style.scss', $package['paths']);
            self::assertContains('frontend/src/style.scss', $package['source']['paths']);
            self::assertContains('assets/logo.svg', $package['source']['paths']);
            self::assertNotContains('assets/build/site.css', $package['source']['paths']);
        });
        self::assertDirectoryDoesNotExist($this->root.'/source/assets/build');
    }

    public function testBuildConfigurationRejectsAmbiguousManagersAndEscapingPaths(): void
    {
        $this->write('manifest.json', json_encode(['code' => 'demo', 'version' => '1.0.0']));
        $this->write('frontend/package.json', json_encode(['scripts' => ['build' => 'vite build']]));
        $this->write('frontend/pnpm-lock.yaml', 'lockfileVersion: 9');
        $this->write('frontend/package-lock.json', '{}');
        $this->assertInvalidPackage('多个包管理器');
        $this->write('ptadmin.release.json', json_encode(['build' => ['directory' => '../outside', 'command' => [PHP_BINARY], 'output' => 'frontend/dist']]));
        $this->assertInvalidPackage('build 必须声明');
        $this->write('ptadmin.release.json', json_encode(['source' => ['exclude' => ['../secret']]]));
        $this->assertInvalidPackage('无效路径');
    }

    private function assertInvalidPackage(string $message, ?string $version = null): void
    {
        try {
            $this->builder->withPackage('addon', $this->root.'/source', $version, static function (): void {
                self::fail('Invalid package must never be consumed.');
            });
            self::fail('Invalid package must fail.');
        } catch (CloudException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root.'/source/'.$relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }
}
