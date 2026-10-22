<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\InstallDatabaseConfigurator;
use PTAdmin\Core\Install\InstallDatabaseDrivers;
use PTAdmin\Core\Install\InstallRequirementService;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class InstallDatabaseDriversTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-install-drivers-'.bin2hex(random_bytes(6));
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
        $app['config']->set('database.default', 'not_configured');
    }

    public function testOptionsReflectPdoCapabilitiesAndSelectAnAvailableDefault(): void
    {
        $drivers = new InstallDatabaseDrivers(['pgsql']);
        $options = array_column($drivers->options(), null, 'driver');
        self::assertFalse($options['sqlite']['available']);
        self::assertFalse($options['mysql']['available']);
        self::assertTrue($options['pgsql']['available']);
        self::assertSame('5432', $options['pgsql']['port']);
        self::assertSame('pdo_pgsql', $options['pgsql']['extension']);
        self::assertSame('pgsql', $drivers->defaultDriver());
        self::assertSame('mysql', (new InstallDatabaseDrivers(['mysql', 'sqlite', 'pgsql']))->defaultDriver());
        self::assertNull((new InstallDatabaseDrivers([]))->defaultDriver());
        self::assertSame([], $this->app['db']->getConnections());
    }

    public function testConfiguredDriverRestrictionsApplyToDefaultsAndBackend(): void
    {
        config(['ptadmin.install.database_drivers' => ['sqlite']]);
        $drivers = new InstallDatabaseDrivers(['pgsql', 'sqlite']);
        self::assertSame(['sqlite'], array_column($drivers->options(), 'driver'));
        self::assertSame('sqlite', $drivers->defaultDriver());
        $this->expectException(InvalidInstallStateException::class);
        $drivers->assertAvailable('pgsql');
    }

    public function testUnavailableDatabaseIsRejectedBeforeFilesOrConnectionsAreCreated(): void
    {
        $this->app->instance(InstallDatabaseDrivers::class, new InstallDatabaseDrivers([]));
        foreach (['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'] as $driver => $extension) {
            try {
                $this->app->make(InstallDatabaseConfigurator::class)->configure(['db_connection' => $driver]);
                self::fail('Missing PDO driver must be rejected.');
            } catch (InvalidInstallStateException $exception) {
                self::assertStringContainsString($extension, $exception->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->storage.'/database');
        self::assertSame('not_configured', config('database.default'));
        self::assertSame([], $this->app['db']->getConnections());
    }

    public function testRequirementNeedsOneAvailableConfiguredDriverWithoutOpeningDatabase(): void
    {
        config(['ptadmin.install.extensions' => [], 'ptadmin.install.folders' => [], 'ptadmin.install.files' => []]);
        $this->app->instance(InstallDatabaseDrivers::class, new InstallDatabaseDrivers(['pgsql']));
        self::assertFalse($this->app->make(InstallRequirementService::class)->hasFailures());
        $this->app->instance(InstallDatabaseDrivers::class, new InstallDatabaseDrivers([]));
        $this->app->forgetInstance(InstallRequirementService::class);
        $failures = $this->app->make(InstallRequirementService::class)->failures();
        self::assertCount(1, $failures);
        self::assertSame(__('ptadmin::install.database_extensions'), $failures[0]['title']);
        self::assertSame([], $this->app['db']->getConnections());
    }
}
