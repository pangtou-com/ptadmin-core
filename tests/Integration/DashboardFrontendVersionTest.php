<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Dashboard\DashboardService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class DashboardFrontendVersionTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-dashboard-frontend-'.bin2hex(random_bytes(6));
        parent::setUp();
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
        $app['config']->set('ptadmin.web_prefix', 'manual_admin');
    }

    public function testMissingManifestDoesNotBreakSummaryOrShowFrontendVersionNotice(): void
    {
        $service = $this->app->make(DashboardService::class);

        self::assertNull($service->summary()['frontend_version']);
        self::assertSame([
            ['id' => 'backend', 'title' => 'PTAdmin Core 0.1.0', 'meta' => '当前版本', 'status' => 'success'],
        ], $service->query(new Admin(), [['code' => 'ptadmin.version-notices']])['results'][0]['data']['items']);
    }

    /** @dataProvider invalidFrontendManifests */
    public function testInvalidFrontendVersionMetadataDoesNotBreakSummary(string $contents): void
    {
        $directory = public_path('manual_admin');
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/manifest.json', $contents);

        self::assertNull($this->app->make(DashboardService::class)->summary()['frontend_version']);
    }

    public static function invalidFrontendManifests(): array
    {
        return [
            'invalid json' => ['{"frontend_version":'],
            'missing version' => ['{}'],
            'invalid version type' => ['{"frontend_version":123}'],
            'blank version' => ['{"frontend_version":"  "}'],
        ];
    }

    public function testReadableFrontendVersionRemainsAvailable(): void
    {
        $directory = public_path('manual_admin');
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/manifest.json', '{"frontend_version":"2.3.4-custom"}');

        self::assertSame('2.3.4-custom', $this->app->make(DashboardService::class)->summary()['frontend_version']);
    }
}
