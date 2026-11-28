<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Updates\{ApplicationPackageBuilder, ApplicationUpdatePlan, ComposerBaselineResolver, DependencyBaseline, PackageManifest, UpdateFiles};

final class ComposerBaselineTest extends TestCase
{
    private string $workspace;
    private string $root;
    private array $document;
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/ptadmin-composer-baseline-'.bin2hex(random_bytes(6));
        $official = $this->workspace.'/official';
        $production = ['name' => 'ptadmin/core', 'version' => '1.0.0', 'source' => ['reference' => str_repeat('a', 40)], 'require' => ['php' => '^8.2']];
        $development = ['name' => 'phpunit/phpunit', 'version' => '11.5.0', 'source' => ['reference' => str_repeat('c', 40)], 'bin' => ['phpunit']];
        UpdateFiles::write($official.'/composer.json', ['name' => 'ptadmin/admin', 'require' => ['ptadmin/core' => '^1.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.5'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]);
        UpdateFiles::write($official.'/composer.lock', ['packages' => [$production], 'packages-dev' => [$development]]);
        UpdateFiles::write($official.'/vendor/composer/installed.json', ['packages' => [$production], 'dev' => false]);
        $shell = $official.'/vendor/ptadmin/core/resources/admin-frontend';
        UpdateFiles::directory($shell);
        file_put_contents($shell.'/index.html', 'shell');
        $files = [['path' => 'index.html', 'size' => 5, 'sha256' => hash('sha256', 'shell')]];
        UpdateFiles::write($shell.'/manifest.json', ['manifest_version' => 1, 'frontend_version' => '1.0.0', 'entry' => './index.html',
            'files' => $files, 'artifact_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES)),
            'backend_constraint' => '^1.0', 'api_protocol' => 1, 'schema_protocol' => 1]);
        foreach (['vendor/autoload.php', 'vendor/composer/installed.php', 'vendor/composer/InstalledVersions.php', 'vendor/composer/ClassLoader.php',
            'vendor/ptadmin/core/production.php', 'public/index.php', 'artisan', 'bootstrap/update-guard.php', 'bootstrap/app.php', 'config/auth.php'] as $path) {
            UpdateFiles::directory(dirname($official.'/'.$path));
            file_put_contents($official.'/'.$path, '<?php // original');
        }
        $report = (new ApplicationPackageBuilder())->build($official, $this->workspace.'/release', '1.0.0', str_repeat('b', 40), '^1.0');
        $this->target = $report['target'];
        $this->root = $this->workspace.'/customer';
        UpdateFiles::copy($official, $this->root);
        unlink($this->root.'/'.PackageManifest::IDENTITY);
        UpdateFiles::write($this->root.'/vendor/composer/installed.json', ['packages' => [$production, $development], 'dev' => true]);
        UpdateFiles::directory($this->root.'/vendor/phpunit/phpunit');
        UpdateFiles::directory($this->root.'/vendor/bin');
        file_put_contents($this->root.'/vendor/phpunit/phpunit/phpunit', '<?php // default development tool');
        file_put_contents($this->root.'/vendor/bin/phpunit', '<?php // generated proxy');
        file_put_contents($this->root.'/vendor/autoload.php', '<?php // locally generated autoloader');
        $this->app->setBasePath($this->root);
        config(['ptadmin-updates.max_archive_bytes' => 10485760, 'ptadmin-updates.max_uncompressed_bytes' => 10485760]);
        $archive = $this->workspace.'/release/full.zip';
        $url = 'https://cloud.api.pangtou.com/api/cloud/official/artifacts/1/download';
        $this->document = ['results' => [['component' => 'application-template', 'status' => 'published', 'version' => '1.0.0',
            'application' => ['target' => array_diff_key($this->target, array_flip(['files', 'baseline'])),
                'artifacts' => [['kind' => 'full', 'url' => $url, 'size' => filesize($archive), 'sha256' => hash_file('sha256', $archive)]]]]]];
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(file_get_contents($archive))]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->workspace);
    }

    public function testDefaultDevelopmentDependenciesQualifyWithoutForgingBuildIdentityAndOnlyUseFullPackage(): void
    {
        $before = PackageManifest::files($this->root);
        (new ComposerBaselineResolver())->prepare($this->document);
        $baseline = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $baseline['status'], $baseline['reason']);
        self::assertSame('composer', $baseline['installation']);
        self::assertTrue($baseline['development_dependencies']);
        self::assertSame($before, PackageManifest::files($this->root));
        self::assertFileDoesNotExist($this->root.'/'.PackageManifest::IDENTITY);
        $target = $this->target;
        $target['version'] = '1.1.0';
        $selection = (new ApplicationUpdatePlan())->select(['version' => '1.1.0', 'application' => ['target' => $target,
            'artifacts' => [['kind' => 'patch', 'source_build_id' => $baseline['build_id'], 'size' => 1], ['kind' => 'full', 'size' => 100]]]], $baseline);
        self::assertSame('full', $selection['artifact']['kind']);
        (new ComposerBaselineResolver())->prepare($this->document);
        Http::assertSentCount(1);
    }

    public function testProductionOnlyComposerInstallationAlsoQualifies(): void
    {
        (new Filesystem())->deleteDirectory($this->root.'/vendor');
        UpdateFiles::copy($this->workspace.'/official/vendor', $this->root.'/vendor');
        file_put_contents($this->root.'/vendor/autoload.php', '<?php // local production autoload');
        (new ComposerBaselineResolver())->prepare($this->document);
        $baseline = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $baseline['status'], $baseline['reason']);
        self::assertFalse($baseline['development_dependencies']);
    }

    public function testCustomDependenciesLockAndSourceChangesAreRejected(): void
    {
        $mutations = [
            'composer.json' => static fn ($value) => array_replace_recursive($value, ['require' => ['custom/package' => '^1.0']]),
            'composer.lock' => static fn ($value) => array_replace_recursive($value, ['packages-dev' => [0 => ['version' => '11.6.0']]]),
            'vendor/composer/installed.json' => static fn ($value) => array_replace_recursive($value, ['packages' => [1 => ['version' => '11.6.0']]]),
        ];
        foreach ($mutations as $path => $mutate) {
            $original = UpdateFiles::read($this->root.'/'.$path);
            UpdateFiles::write($this->root.'/'.$path, $mutate($original));
            $this->assertRejected();
            UpdateFiles::write($this->root.'/'.$path, $original);
        }
        file_put_contents($this->root.'/vendor/ptadmin/core/production.php', 'custom source');
        $this->assertRejected();
    }

    public function testExtraGeneratedDirectoryFilesAndModifiedStandardEntrypointAreRejected(): void
    {
        file_put_contents($this->root.'/vendor/composer/custom.php', 'unexpected');
        $this->assertRejected();
        unlink($this->root.'/vendor/composer/custom.php');
        file_put_contents($this->root.'/bootstrap/app.php', 'custom entrypoint');
        $this->assertRejected();
    }

    public function testChangesAfterVerificationCannotBeRecapturedByCheckingAgain(): void
    {
        (new ComposerBaselineResolver())->prepare($this->document);
        $receipt = file_get_contents($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        file_put_contents($this->root.'/vendor/phpunit/phpunit/phpunit', 'changed after verification');
        self::assertSame('unknown', (new DependencyBaseline())->inspect(null, true)['status']);
        (new ComposerBaselineResolver())->prepare($this->document);
        self::assertSame($receipt, file_get_contents($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY));
        self::assertSame('unknown', (new DependencyBaseline())->inspect(null, true)['status']);
        Http::assertSentCount(1);
    }

    public function testDescriptionChangesAfterVerificationPreserveEligibility(): void
    {
        (new ComposerBaselineResolver())->prepare($this->document);
        $composer = UpdateFiles::read($this->root.'/composer.json');
        $composer['name'] = 'customer/application';
        $composer['description'] = '客户项目说明，不改变依赖';
        UpdateFiles::write($this->root.'/composer.json', $composer);
        $baseline = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $baseline['status'], $baseline['reason']);
    }

    public function testCurrentVersionMustBePublishedWithExactCommit(): void
    {
        $document = $this->document;
        $document['results'][0]['application']['target']['core_commit'] = str_repeat('d', 40);
        (new ComposerBaselineResolver())->prepare($document);
        $document = $this->document;
        $document['results'][0]['status'] = 'withdrawn';
        (new ComposerBaselineResolver())->prepare($document);
        self::assertFileDoesNotExist($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        Http::assertNothingSent();
    }

    public function testCorruptOfficialIdentityCannotBeAdopted(): void
    {
        UpdateFiles::write($this->root.'/'.PackageManifest::IDENTITY, ['broken' => true]);
        $this->assertRejected();
        Http::assertNothingSent();
    }

    public function testArchiveMustMatchCatalogDigestAndTarget(): void
    {
        $original = $this->document;
        $this->document['results'][0]['application']['artifacts'][0]['sha256'] = str_repeat('0', 64);
        $this->assertRejected();
        $this->document = $original;
        $this->document['results'][0]['application']['target']['build_id'] = str_repeat('0', 64);
        $this->assertRejected();
    }

    public function testDefaultDevelopmentInstallWithExistingOfficialIdentityAlsoQualifies(): void
    {
        UpdateFiles::write($this->root.'/'.PackageManifest::IDENTITY, $this->target);
        (new ComposerBaselineResolver())->prepare($this->document);
        $baseline = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $baseline['status'], $baseline['reason']);
        self::assertSame('composer', $baseline['installation']);
        self::assertTrue($baseline['development_dependencies']);
    }

    public function testManagedDeploymentsDoNotDownloadOrAdoptComposerBaseline(): void
    {
        foreach (['git', 'ci'] as $delivery) {
            config(['ptadmin-updates.delivery' => $delivery]);
            (new ComposerBaselineResolver())->prepare($this->document);
        }
        config(['ptadmin-updates.delivery' => null]);
        UpdateFiles::directory($this->root.'/.git');
        (new ComposerBaselineResolver())->prepare($this->document);
        self::assertFileDoesNotExist($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        Http::assertNothingSent();
    }

    public function testProductionIdentityIgnoresObsoleteComposerReceipt(): void
    {
        (new Filesystem())->deleteDirectory($this->root.'/vendor');
        UpdateFiles::copy($this->workspace.'/official/vendor', $this->root.'/vendor');
        UpdateFiles::write($this->root.'/'.PackageManifest::IDENTITY, $this->target);
        UpdateFiles::directory(dirname($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY));
        file_put_contents($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY, 'obsolete corrupt receipt');
        self::assertSame('standard', (new DependencyBaseline())->inspect(null, true)['status']);
    }

    public function testSymlinkedSourceCannotQualify(): void
    {
        $file = $this->root.'/vendor/ptadmin/core/production.php';
        unlink($file);
        symlink($this->workspace.'/official/vendor/ptadmin/core/production.php', $file);
        $this->assertRejected();
    }

    private function assertRejected(): void
    {
        try {
            (new ComposerBaselineResolver())->prepare($this->document);
            self::fail('非标准或损坏的来源必须拒绝核验');
        } catch (\RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        self::assertSame('unknown', (new DependencyBaseline())->inspect(null, true)['status']);
    }
    public function testProductionComposerRegenerationWithOfficialIdentity(): void
    {
        (new Filesystem())->deleteDirectory($this->root.'/vendor');
        UpdateFiles::copy($this->workspace.'/official/vendor', $this->root.'/vendor');
        UpdateFiles::write($this->root.'/'.PackageManifest::IDENTITY, $this->target);
        file_put_contents($this->root.'/vendor/autoload.php', '<?php // regenerated Composer autoload');
        PackageManifest::verifyComposerInstallation($this->root, $this->target);
        (new ComposerBaselineResolver())->prepare($this->document);
        $result = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $result['status'], $result['reason']);
        self::assertSame('composer', $result['installation']);
    }

    public static function officialVersionChanges(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officialVersionChanges')]
    public function testReceiptDoesNotPreventVerificationAfterOfficialComposerVersionChange(bool $keepOfficialIdentity, bool $modifySource): void
    {
        if ($keepOfficialIdentity) {
            UpdateFiles::write($this->root.'/'.PackageManifest::IDENTITY, $this->target);
        }
        (new ComposerBaselineResolver())->prepare($this->document);
        $originalReceipt = file_get_contents($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        $next = $this->workspace.'/next';
        UpdateFiles::copy($this->workspace.'/official', $next);
        $composer = UpdateFiles::read($next.'/composer.json');
        $composer['require']['ptadmin/core'] = '^1.1';
        UpdateFiles::write($next.'/composer.json', $composer);
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $path) {
            $data = UpdateFiles::read($next.'/'.$path);
            $data['packages'][0]['version'] = '1.1.0';
            $data['packages'][0]['source']['reference'] = str_repeat('d', 40);
            UpdateFiles::write($next.'/'.$path, $data);
        }
        $shellPath = $next.'/vendor/ptadmin/core/resources/admin-frontend/manifest.json';
        $shell = UpdateFiles::read($shellPath);
        $shell['frontend_version'] = '1.1.0';
        UpdateFiles::write($shellPath, $shell);
        $report = (new ApplicationPackageBuilder())->build($next, $this->workspace.'/next-release', '1.1.0', str_repeat('e', 40), '^1.0');
        (new Filesystem())->deleteDirectory($this->root.'/vendor');
        foreach (['vendor', 'composer.json', 'composer.lock'] as $path) {
            UpdateFiles::copy($next.'/'.$path, $this->root.'/'.$path);
        }
        $archive = $this->workspace.'/next-release/full.zip';
        $url = 'https://cloud.api.pangtou.com/api/cloud/official/artifacts/2/download';
        $nextRelease = $this->document['results'][0];
        $nextRelease['version'] = '1.1.0';
        $nextRelease['application'] = ['target' => array_diff_key($report['target'], array_flip(['files', 'baseline'])),
            'artifacts' => [['kind' => 'full', 'url' => $url, 'size' => filesize($archive), 'sha256' => hash_file('sha256', $archive)]]];
        $this->document['results'][] = $nextRelease;
        Http::fake([$url => Http::response(file_get_contents($archive))]);
        PackageManifest::verifyComposerInstallation($this->root, $report['target']);
        if ($modifySource) {
            file_put_contents($this->root.'/vendor/ptadmin/core/production.php', 'modified production source');
            try {
                (new ComposerBaselineResolver())->prepare($this->document);
                self::fail('换版不能认领修改过的生产源码');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('生产依赖', $error->getMessage());
            }
            self::assertSame($originalReceipt, file_get_contents($this->root.'/'.DependencyBaseline::COMPOSER_IDENTITY));
            self::assertNotSame('standard', (new DependencyBaseline())->inspect(null, true)['status']);
            return;
        }
        (new ComposerBaselineResolver())->prepare($this->document);
        $result = (new DependencyBaseline())->inspect(null, true);
        self::assertSame('standard', $result['status'], $result['reason']);
        self::assertSame('composer', $result['installation']);
    }
}
