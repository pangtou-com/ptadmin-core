<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Cache\RateLimiter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class InstallationBootstrapTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-install-bootstrap-'.bin2hex(random_bytes(6));
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
        $app['config']->set([
            'database.default' => 'not_configured',
            'cache.default' => 'business-cache',
            'cache.limiter' => 'business-limiter',
            'cache.stores.business-cache' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
            'cache.stores.business-limiter' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
            'session.driver' => 'database',
        ]);
        if (is_file($this->storage.'/app/ptadmin/install/installed.json')) {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
    }

    protected function resolveApplicationRateLimiting($app): void
    {
        // 与宿主 RouteServiceProvider 一致，在 Provider 启动阶段注册限流规则。
        $app->booted(function () use ($app): void {
            parent::resolveApplicationRateLimiting($app);
        });
    }

    public function testCacheRateLimiterAndRedirectWorkWithoutDatabaseConfiguration(): void
    {
        $this->app['cache']->put('installation-probe', 'ready', 60);
        self::assertSame('ready', $this->app['cache']->get('installation-probe'));
        self::assertSame(1, $this->app->make(RateLimiter::class)->hit('installation-probe'));
        self::assertSame('/__ptadmin__/install', parse_url(redirect('/__ptadmin__/install')->getTargetUrl(), PHP_URL_PATH));
        self::assertSame([], $this->app['db']->getConnections());
    }

    public function testInstalledApplicationKeepsDatabaseCacheAndSessionConfiguration(): void
    {
        $this->app->make(InstallStateStore::class)->markInstalled('0.1.0', 'admin_test');
        $this->refreshApplication();

        self::assertSame('business-cache', config('cache.default'));
        self::assertSame('business-limiter', config('cache.limiter'));
        self::assertSame('database', config('session.driver'));
        self::assertSame('database', config('cache.stores.business-cache.driver'));
        self::assertSame('testing', config('database.default'));
    }

    public function testAllHostRoutesAreBlockedBeforeControllerConstructionAndMiddleware(): void
    {
        $router = $this->app['router'];
        $router->get('/business-web', InstallationDatabaseProbeController::class)->middleware('web');
        $router->post('/business-api', InstallationDatabaseProbeController::class)->middleware('api');
        $router->get('/business-direct', InstallationDatabaseProbeController::class);
        $router->get('/__ptadmin__/install-business', InstallationDatabaseProbeController::class);
        $router->aliasMiddleware('database-probe', InstallationDatabaseProbeMiddleware::class);
        $router->get('/business-middleware', static fn () => response('ready'))->middleware('database-probe');

        $this->get('/business-web')->assertRedirect('/__ptadmin__/install');
        $this->postJson('/business-api')->assertRedirect('/__ptadmin__/install');
        $this->getJson('/business-direct')->assertRedirect('/__ptadmin__/install');
        $this->get('/__ptadmin__/install-business')->assertRedirect('/__ptadmin__/install');
        $this->get('/business-middleware')->assertRedirect('/__ptadmin__/install');
        $this->getJson('/unknown-route')->assertRedirect('/__ptadmin__/install');
        $this->getJson('/ptadmin/system/updates')->assertRedirect('/__ptadmin__/install');
        $this->postJson('/ptadmin/system/updates/check')->assertRedirect('/__ptadmin__/install');
        self::assertArrayNotHasKey('ptadmin:updates:check', \Illuminate\Support\Facades\Artisan::all());
        self::assertArrayNotHasKey('ptadmin:theme:publish', \Illuminate\Support\Facades\Artisan::all());
        $this->postJson('/ptadmin/site/themes/default/1.0.0/publish')->assertRedirect('/__ptadmin__/install');
        self::assertSame([], app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->getJson('/__ptadmin__/install/status')->assertOk()->assertJsonPath('installed', false);
        self::assertSame([], $this->app['db']->getConnections());
    }

    public function testThemePublicationBeforeInstallDoesNotOpenOrCreateSqlite(): void
    {
        $database = $this->storage.'/missing.sqlite';
        config(['database.default' => 'uninstalled', 'database.connections.uninstalled' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        self::assertArrayNotHasKey('ptadmin:theme:publish', \Illuminate\Support\Facades\Artisan::all());
        $this->postJson('/ptadmin/site/themes/default/1.0.0/publish')->assertRedirect('/__ptadmin__/install');
        self::assertSame([], $this->app['db']->getConnections());
        self::assertFileDoesNotExist($database);
    }

    public function testWizardUsesSameOriginUrlsWithoutOpeningMissingDatabase(): void
    {
        $database = $this->storage.'/missing.sqlite';
        config([
            'database.default' => 'uninstalled',
            'database.connections.uninstalled' => [
                'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'ptadmin.install.extensions' => [],
            'ptadmin.install.folders' => [],
            'ptadmin.install.files' => [],
        ]);
        // 模拟 HTTPS 在代理终止、PHP 只收到 HTTP 的部署方式。
        $this->withHeader('X-Forwarded-Proto', 'https');
        $this->get('http://install.example.test/business')
            ->assertHeader('Location', '/__ptadmin__/install');
        $this->get('/__ptadmin__/install/account')
            ->assertHeader('Location', route('ptadmin.install.welcome', [
                'redirect' => '/__ptadmin__/install/account', 'error' => 'protocol',
            ], false));
        $this->get('/__ptadmin__/install')
            ->assertOk()->assertDontSee('http://install.example.test/__ptadmin__/install', false);
        $this->post('/__ptadmin__/install/accept')
            ->assertHeader('Location', '/__ptadmin__/install/requirements');
        $this->get('/__ptadmin__/install/requirements')
            ->assertOk()->assertDontSee('http://install.example.test/__ptadmin__/install', false);
        $response = $this->get('/__ptadmin__/install/account')
            ->assertOk()->assertDontSee('http://install.example.test/__ptadmin__/install', false);

        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        self::assertSame(3, $xpath->query('//form')->length);
        self::assertSame(0, $xpath->query('//form//form')->length);
        foreach ($xpath->query('//form') as $form) {
            self::assertSame('/__ptadmin__/install/stream', $form->getAttribute('action'));
            self::assertSame('post', $form->getAttribute('method'));
        }
        self::assertSame(0, $xpath->query('//form[.//input[@name="db_password"]]//input[@name="password"]')->length);
        self::assertStringStartsWith('data:image/svg+xml,', $xpath->query('//link[@rel="icon"]')->item(0)->getAttribute('href'));
        $invalid = $this->post('/__ptadmin__/install/stream', ['username' => 'bad!'])->assertOk();
        self::assertStringContainsString('"type":"error"', $invalid->streamedContent());
        self::assertSame([], $this->app['db']->getConnections());
        self::assertFileDoesNotExist($database);
    }

    public function testInstalledHostCanRunBusinessQueriesAndCannotReopenInstaller(): void
    {
        $this->app->make(InstallStateStore::class)->markInstalled('0.1.0', 'admin_test');
        $this->refreshApplication();
        $this->app['router']->get('/business-direct', InstallationDatabaseProbeController::class);
        $this->get('/business-direct')->assertOk()->assertSee('ready');
        $this->get('/__ptadmin__/install')->assertNotFound();
        self::assertCount(1, $this->app['db']->getConnections());
    }
}

/** 若安装检查晚于路由解析，构造函数会尝试使用未配置的数据库并使请求失败。 */
final class InstallationDatabaseProbeController extends Controller
{
    public function __construct()
    {
        DB::select('select 1');
    }

    public function __invoke(): \Symfony\Component\HttpFoundation\Response
    {
        return response('ready');
    }
}

final class InstallationDatabaseProbeMiddleware
{
    public function handle(\Illuminate\Http\Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        DB::select('select 1');

        return $next($request);
    }
}
