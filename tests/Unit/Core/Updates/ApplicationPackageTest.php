<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Updates;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Updates\{ApplicationPackage, ApplicationPackageBuilder, DependencyBaseline, PackageManifest, UpdateFiles};

final class ApplicationPackageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-packages-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
    }

    private function project(string $name, string $version): string
    {
        $root = $this->root.'/'.$name;
        $package = ['name' => 'ptadmin/core', 'version' => $version, 'source' => ['reference' => str_repeat('a', 40)], 'require' => ['php' => '^8.2']];
        UpdateFiles::write($root.'/composer.json', ['name' => 'ptadmin/admin', 'require' => ['ptadmin/core' => $version], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]);
        UpdateFiles::write($root.'/composer.lock', ['packages' => [$package], 'packages-dev' => []]);
        UpdateFiles::write($root.'/vendor/composer/installed.json', ['packages' => [$package], 'dev' => false]);
        $shellRoot = $root.'/vendor/ptadmin/core/resources/admin-frontend';
        UpdateFiles::directory($shellRoot);
        file_put_contents($shellRoot.'/index.html', 'shell');
        $shellFiles = [['path' => 'index.html', 'size' => 5, 'sha256' => hash('sha256', 'shell')]];
        UpdateFiles::write($shellRoot.'/manifest.json', ['manifest_version' => 1, 'frontend_version' => $version,
            'entry' => './index.html', 'files' => $shellFiles, 'artifact_sha256' => hash('sha256', json_encode($shellFiles, JSON_UNESCAPED_SLASHES)),
            'backend_constraint' => '^1.0', 'api_protocol' => 1, 'schema_protocol' => 1]);
        foreach (['vendor/autoload.php', 'vendor/composer/installed.php', 'vendor/composer/InstalledVersions.php', 'public/index.php', 'artisan', 'bootstrap/update-guard.php', 'bootstrap/app.php', 'config/auth.php', 'app/Business.php'] as $path) {
            UpdateFiles::directory(dirname($root.'/'.$path));
            file_put_contents($root.'/'.$path, '<?php // '.($path === 'vendor/autoload.php' ? $version : 'host'));
        }
        return $root;
    }

    public function testFullAndPatchProduceIdenticalTargetsAndPreserveBusiness(): void
    {
        $a = $this->project('a', '1.0.0');
        file_put_contents($a.'/vendor/removed.php', 'old');
        UpdateFiles::directory($a.'/vendor/becomes-file');
        file_put_contents($a.'/vendor/becomes-file/old.php', 'old');
        file_put_contents($a.'/vendor/becomes-directory', 'old');
        $builder = new ApplicationPackageBuilder();
        $old = $builder->build($a, $this->root.'/a-release', '1.0.0', str_repeat('b', 40), '>=1.0 <2.0');
        $b = $this->project('b', '1.1.0');
        file_put_contents($b.'/vendor/added.php', 'new');
        file_put_contents($b.'/vendor/becomes-file', 'new');
        UpdateFiles::directory($b.'/vendor/becomes-directory');
        file_put_contents($b.'/vendor/becomes-directory/new.php', 'new');
        $next = $builder->build($b, $this->root.'/b-release', '1.1.0', str_repeat('c', 40), '>=1.0 <1.1', $a);
        $packages = new ApplicationPackage();
        foreach (['full', 'patch'] as $kind) {
            $packages->extract($this->root.'/b-release/'.$kind.'.zip', $this->root.'/'.$kind.'-bundle');
            $target = $packages->prepare($this->root.'/'.$kind.'-bundle', $this->root.'/'.$kind, $a);
            self::assertSame(PackageManifest::canonical($next['target']), PackageManifest::canonical($target));
            PackageManifest::verify($this->root.'/'.$kind, $target, false);
            self::assertFileDoesNotExist($this->root.'/'.$kind.'/vendor/removed.php');
            self::assertSame('new', file_get_contents($this->root.'/'.$kind.'/vendor/added.php'));
        }
        self::assertSame(PackageManifest::files($this->root.'/full'), PackageManifest::files($this->root.'/patch'));
        self::assertFileExists($a.'/vendor/removed.php');
        self::assertFileExists($a.'/app/Business.php');
        self::assertSame('standard', (new DependencyBaseline())->inspect($a, true)['status']);
        self::assertNotSame($old['target']['build_id'], $next['target']['build_id']);
    }

    public function testDescriptionsDoNotChangeEligibilityButCustomDependenciesDo(): void
    {
        $root = $this->project('a', '1.0.0');
        (new ApplicationPackageBuilder())->build($root, $this->root.'/release', '1.0.0', str_repeat('b', 40), '^1.0');
        $composer = UpdateFiles::read($root.'/composer.json');
        $composer['name'] = 'my/application';
        $composer['description'] = '自己的应用';
        UpdateFiles::write($root.'/composer.json', $composer);
        self::assertSame('standard', (new DependencyBaseline())->inspect($root, true)['status']);
        foreach (['require' => ['other/package' => '^1.0'], 'require-dev' => ['other/dev-package' => '^1.0'],
            'autoload' => ['psr-4' => ['Custom\\' => 'custom/']], 'repositories' => [['type' => 'composer', 'url' => 'https://example.test']]] as $key => $value) {
            $modified = $composer;
            $modified[$key] = $value;
            UpdateFiles::write($root.'/composer.json', $modified);
            $result = (new DependencyBaseline())->inspect($root, true);
            self::assertSame('custom', $result['status']);
            self::assertStringContainsString($key, $result['reason']);
        }
    }

    public function testModifiedSourceCannotApplyPatch(): void
    {
        $a = $this->project('a', '1.0.0');
        $builder = new ApplicationPackageBuilder();
        $builder->build($a, $this->root.'/a-release', '1.0.0', str_repeat('b', 40), '^1.0');
        $b = $this->project('b', '1.1.0');
        $builder->build($b, $this->root.'/b-release', '1.1.0', str_repeat('c', 40), '^1.0', $a);
        $package = new ApplicationPackage();
        $package->extract($this->root.'/b-release/patch.zip', $this->root.'/bundle');
        file_put_contents($a.'/vendor/autoload.php', 'modified');
        self::assertSame('unknown', (new DependencyBaseline())->inspect($a, true)['status']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('官方构建不一致');
        $package->prepare($this->root.'/bundle', $this->root.'/stage', $a);
    }

    public function testArchiveRejectsUnlistedFileBeforeExtraction(): void
    {
        $root = $this->project('a', '1.0.0');
        (new ApplicationPackageBuilder())->build($root, $this->root.'/release', '1.0.0', str_repeat('b', 40), '^1.0');
        $zip = new \ZipArchive();
        $zip->open($this->root.'/release/full.zip');
        $zip->addFromString('payload/../escape.php', '<?php');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new ApplicationPackage())->extract($this->root.'/release/full.zip', $this->root.'/stage');
    }
}
