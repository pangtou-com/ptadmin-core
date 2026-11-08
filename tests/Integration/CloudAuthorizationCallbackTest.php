<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Cloud\CloudProtocol;
use PTAdmin\Cloud\Contracts\CloudSessionStoreInterface;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class CloudAuthorizationCallbackTest extends TestCase
{
    private string $storage;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-cloud-callback-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(ApplicationIdentity::class)->createForInstall();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public static function redirectTargets(): array
    {
        return [
            'site prefix' => ['admin_6c24fd45aea7', '', '/admin_6c24fd45aea7/#/cloud/market'],
            'prefix with slashes' => ['/console/', '', '/console/#/cloud/market'],
            'nested prefix' => ['team/console', '', '/team/console/#/cloud/market'],
            'custom redirect' => ['console', 'https://console.example/portal/#/cloud/market', 'https://console.example/portal/#/cloud/market'],
        ];
    }

    #[DataProvider('redirectTargets')]
    public function testSuccessfulAuthorizationReturnsToTheShellRoute(string $prefix, string $override, string $target): void
    {
        config(['ptadmin.web_prefix' => $prefix, 'ptadmin.cloud.callback_redirect' => $override]);
        $admin = Admin::query()->forceCreate([
            'username' => 'cloud-founder', 'nickname' => '创始人', 'password' => 'unused', 'status' => 1, 'is_founder' => false,
        ]);
        app(\PTAdmin\Admin\Navigation\MenuSynchronizer::class)->syncCore();
        app(\PTAdmin\Core\Install\InitialAdministratorPermissions::class)->grant($admin);
        $token = app(SessionManager::class)->issue('admin', $admin->id, 'admin')->toArray()['access_token'];
        $authorization = [];
        Http::fake(function (Request $request) use (&$authorization) {
            if ($request->url() === CloudProtocol::SITE_URL.'/api/cloud/requests') {
                $authorization = $request->data();
                return Http::response(['code' => 0, 'data' => ['request_key' => str_repeat('a', 64)]]);
            }
            self::assertSame(CloudProtocol::SITE_URL.'/api/cloud/token', $request->url());
            self::assertSame($authorization['redirect_uri'], $request['redirect_uri']);
            self::assertSame($authorization['code_challenge'], CloudProtocol::challenge($request['code_verifier']));
            return Http::response(['code' => 0, 'data' => [
                'access_token' => 'cloud-access', 'refresh_token' => 'cloud-refresh', 'expires_at' => time() + 3600,
            ]]);
        });

        $response = $this->withToken($token)->getJson('/ptadmin/cloud/session/authorize')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'ptadmin_cloud_'));
        self::assertNotNull($cookie);
        $callback = $authorization['redirect_uri'].'?'.http_build_query(['state' => $authorization['state'], 'code' => str_repeat('c', 64)]);
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get($callback)
            ->assertRedirect($target)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertSame('cloud-access', app(CloudSessionStoreInterface::class)->get()['access_token']);
        $this->get($callback)->assertStatus(422);
        Http::assertSentCount(2);
    }
}
