<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Http\Responses\AdminResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AdminExceptionContractTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-exceptions-'.bin2hex(random_bytes(6));
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
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $app['config']->set('app.debug', true);
        $directory = $this->storage.'/app/ptadmin/install';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    public function testUnmatchedPluginRouteHasAdminEnvelopeAndRequestIdWithoutAcceptHeader(): void
    {
        $response = $this->get('/ptadmin/cms/missing', ['X-Request-ID' => 'cms-retry-1']);
        $response->assertNotFound()->assertExactJson([
            'code' => 40400, 'message' => '请求的接口或资源不存在。', 'data' => [],
        ])->assertHeader('X-Request-ID', 'cms-retry-1');
        $this->getJson('/ptadmin/cms/missing', ['X-Request-ID' => 'invalid request id'])
            ->assertNotFound()->assertHeader('X-Request-ID');
    }

    public function testMethodAndThrottleExceptionsPreserveHttpHeaders(): void
    {
        $this->app['router']->post('/ptadmin/probe', static fn () => 'ok');
        $this->getJson('/ptadmin/probe')->assertStatus(405)->assertJsonPath('code', 40500)->assertHeader('Allow', 'POST')->assertHeader('X-Request-ID');
        $this->app['router']->get('/ptadmin/limited', static function (): void {
            throw new TooManyRequestsHttpException(30, '请稍后重试。');
        });
        $this->getJson('/ptadmin/limited')->assertStatus(429)->assertJsonPath('code', 42900)->assertHeader('Retry-After', '30');
    }

    public function testValidationAuthenticationAndServerErrorsUseAdminEnvelope(): void
    {
        $this->app['router']->get('/ptadmin/invalid', static function (): void {
            throw ValidationException::withMessages(['title' => ['标题不能为空。']]);
        });
        $this->app['router']->get('/ptadmin/unauthenticated', static function (): void {
            throw new AuthenticationException();
        });
        $this->app['router']->get('/ptadmin/failure', static function (): void {
            throw new \RuntimeException('private-database-credential');
        });
        $this->getJson('/ptadmin/invalid')->assertStatus(422)->assertJsonPath('code', 42200)->assertJsonPath('data.errors.title.0', '标题不能为空。');
        $this->get('/ptadmin/unauthenticated')->assertStatus(401)->assertJsonPath('code', 40100);
        $this->getJson('/ptadmin/failure')->assertStatus(500)->assertExactJson([
            'code' => 50000, 'message' => '服务器内部错误，请稍后重试。', 'data' => [],
        ])->assertHeader('X-Request-ID');
    }

    public function testConfiguredPrefixAndPublicResponsesKeepTheirBoundaries(): void
    {
        config(['ptadmin.api_prefix' => 'manage/api']);
        $this->getJson('/manage/api/missing')->assertNotFound()->assertJsonPath('code', 40400);
        foreach (['/manage/api-extra/missing', '/ptadmin/missing', '/public/missing'] as $path) {
            $response = $this->getJson($path)->assertNotFound();
            self::assertArrayNotHasKey('code', $response->json());
            self::assertFalse($response->headers->has('X-Request-ID'));
        }
        $this->app['router']->get('/manage/api/business', static fn () => AdminResponse::error(40901, '业务冲突。', ['key' => 'value'], 409));
        $this->getJson('/manage/api/business')->assertStatus(409)->assertExactJson([
            'code' => 40901, 'message' => '业务冲突。', 'data' => ['key' => 'value'],
        ])->assertHeader('X-Request-ID');
    }

    public function testCacheRepairCommandBootsWithCorruptSnapshot(): void
    {
        $path = $this->storage.'/app/ptadmin/addons/catalog.json';
        file_put_contents($path, '{broken');
        $arguments = $_SERVER['argv'];
        try {
            $_SERVER['argv'] = ['artisan', 'ptadmin:addon:cache'];
            $this->refreshApplication();
            $this->artisan('ptadmin:addon:cache')->assertSuccessful();
            self::assertSame([], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['addons']);
        } finally {
            $_SERVER['argv'] = $arguments;
        }
    }
}
