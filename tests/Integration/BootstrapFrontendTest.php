<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use RuntimeException;

final class BootstrapFrontendTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-bootstrap-frontend-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->app->make(ApplicationIdentity::class)->createForInstall();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app->usePublicPath($this->storage.'/public');
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $app['config']->set('ptadmin.frontend.bundled_path', $this->storage.'/missing-build');
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'manual_admin', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    public function testManualDeploymentReadsVersionWithoutBuildDirectoryOrReleaseChecksums(): void
    {
        $directory = public_path('manual_admin');
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/index.html', '<html>Manual deployment</html>');
        file_put_contents($directory.'/manifest.json', '{"frontend_version":"2.3.4-custom"}');

        $this->getJson('/ptadmin/bootstrap')
            ->assertOk()->assertJsonPath('code', 0)
            ->assertJsonPath('data.runtime.web_prefix', 'manual_admin')
            ->assertJsonPath('data.runtime.frontend_version', '2.3.4-custom');
    }

    public function testMissingPublishedDirectoryDoesNotUseBundledVersion(): void
    {
        config(['ptadmin.frontend.bundled_path' => dirname(__DIR__, 2).'/resources/admin-frontend']);
        $this->app->make(AdminFrontendManifest::class)->verify(config('ptadmin.frontend.bundled_path'));

        $this->getJson('/ptadmin/bootstrap')->assertOk()
            ->assertJsonPath('data.runtime.frontend_version', 'unknown');
    }

    public function testMissingFrontendDirectoriesDoNotBlockBootstrap(): void
    {
        $this->getJson('/ptadmin/bootstrap')->assertOk()
            ->assertJsonPath('data.runtime.frontend_version', 'unknown');
    }

    public function testMissingManifestDoesNotBlockManualDeployment(): void
    {
        mkdir(public_path('manual_admin'), 0700, true);
        file_put_contents(public_path('manual_admin/index.html'), '<html>Manual deployment</html>');

        $this->getJson('/ptadmin/bootstrap')->assertOk()
            ->assertJsonPath('data.runtime.frontend_version', 'unknown');
    }

    /** @dataProvider invalidMetadata */
    public function testInvalidVersionMetadataDoesNotBlockBootstrap(string $contents): void
    {
        mkdir(public_path('manual_admin'), 0700, true);
        file_put_contents(public_path('manual_admin/manifest.json'), $contents);

        $this->getJson('/ptadmin/bootstrap')->assertOk()
            ->assertJsonPath('data.runtime.frontend_version', 'unknown');
    }

    public static function invalidMetadata(): array
    {
        return [
            'partial upload' => ['{"frontend_version":'],
            'scalar' => ['"0.1.0"'],
            'missing version' => ['{}'],
            'invalid type' => ['{"frontend_version":123}'],
            'blank version' => ['{"frontend_version":"  "}'],
        ];
    }

    public function testRuntimeDoesNotCheckAssetsButReleaseVerificationStillRejectsChangedFiles(): void
    {
        $directory = public_path('manual_admin');
        (new Filesystem())->copyDirectory(dirname(__DIR__, 2).'/resources/admin-frontend', $directory);
        file_put_contents($directory.'/index.html', '<html>Manually updated</html>');

        $this->getJson('/ptadmin/bootstrap')->assertOk()
            ->assertJsonPath('data.runtime.frontend_version', '0.1.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PTAdmin frontend file failed verification: index.html');
        $this->app->make(AdminFrontendManifest::class)->verify($directory);
    }
}
