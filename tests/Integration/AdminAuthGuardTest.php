<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Auth\AdminAuth as AuthService;
use PTAdmin\Admin\Auth\AuthorizationIdentity;
use PTAdmin\Admin\Auth\AuthorizationIdentityResolverInterface;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Auth\Exceptions\AdminAuthenticationException;
use PTAdmin\Admin\Facades\AdminAuth;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;

final class AdminAuthGuardTest extends TestCase
{
    private string $storage;
    private Admin $admin;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-guard-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->storage);
        $app['config']->set([
            'auth.defaults.guard' => 'web',
            'auth.guards.ptadmin' => ['driver' => 'ptadmin-session'],
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'session.driver' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'ptadmin.addon.path' => $this->storage.'/addons',
        ]);
        (new InstallStateStore($this->storage.'/app/ptadmin/install'))->markInstalled('0.12.5', 'admin_test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->admin = Admin::query()->forceCreate(['username' => 'guard-admin', 'password' => 'unused', 'status' => 1]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testHttpConsumersShareOneAdminAndOneSessionResolution(): void
    {
        $this->admin->forceFill(['is_founder' => true])->save();
        $this->app['router']->get('/ptadmin/identity-probe', function (Request $request) {
            $admin = AdminAuth::authenticate();
            self::assertSame($admin, Auth::guard('ptadmin')->user());
            self::assertSame($admin, $request->user('ptadmin'));
            self::assertSame($admin, app(SchemaSubjectContextInterface::class)->get());
            self::assertSame($admin, app(AuthService::class)->user());
            self::assertSame($admin, app(\PTAdmin\Admin\Auth\FounderAccess::class)->resolve($request));
            self::assertSame($admin, app(\PTAdmin\Cloud\CloudAccess::class)->assert($request));
            self::assertSame($admin->id, AdminAuth::id());
            self::assertTrue(AdminAuth::check());
            return response()->json(['id' => $admin->id]);
        })->middleware('ptadmin.session');

        $token = $this->token();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/ptadmin/identity-probe')->assertOk()->assertJsonPath('id', $this->admin->id);
        $this->assertAuthenticationQueries(1, 1, 1);
        self::assertNull(AdminAuth::user());
        self::assertNull(app(SchemaSubjectContextInterface::class)->get());

        DB::flushQueryLog();
        $this->withToken($token)->postJson('/ptadmin/session/renew')->assertOk()->assertJsonStructure(['data' => ['expires_at', 'absolute_expires_at']]);
        $this->assertAuthenticationQueries(1, 1, 1);
    }

    public function testNewRequestsDoNotInheritSuccessfulOrFailedAuthentication(): void
    {
        $second = Admin::query()->forceCreate(['username' => 'second', 'password' => 'unused', 'status' => 1]);
        $this->bindRequest($this->token());
        self::assertSame($this->admin->id, AdminAuth::id());
        $this->bindRequest($this->token($second));
        self::assertSame($second->id, AdminAuth::id());
        $this->bindRequest();
        DB::enableQueryLog();
        self::assertFalse(AdminAuth::check());
        self::assertSame([], AdminAuth::roles());
        self::assertSame([], AdminAuth::permissions());
        self::assertFalse(AdminAuth::can('dashboard.view'));
        self::assertFalse(AdminAuth::hasPerm('dashboard.view'));
        self::assertFalse(AdminAuth::anyRole('editor'));
        self::assertFalse(AdminAuth::anyRole(['editor', 'reviewer']));
        self::assertFalse(AdminAuth::anyRole([]));
        self::assertSame([], DB::getQueryLog());

        $this->bindRequest('invalid-token');
        DB::flushQueryLog();
        self::assertNull(AdminAuth::user());
        self::assertNull(AdminAuth::user());
        $this->assertAuthenticationQueries(0, 1, 0);
        $this->expectException(AdminAuthenticationException::class);
        AdminAuth::authorize('dashboard.view');
    }

    public function testIncorrectSubjectAndGuardExpiredRevokedAndInactiveAccountsAreRejected(): void
    {
        $sessions = app(SessionManager::class);
        $wrongSubject = $sessions->issue(\PTAdmin\User\Models\User::class, $this->admin->id, 'admin')->toArray()['access_token'];
        $wrongGuard = $sessions->issue(Admin::class, $this->admin->id, 'web')->toArray()['access_token'];
        foreach ([$wrongSubject, $wrongGuard] as $token) {
            $this->withToken($token)->getJson('/ptadmin/session')->assertUnauthorized()->assertJsonPath('code', 40102);
        }
        $expired = $this->token();
        DB::table('ptadmin_sessions')->where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);
        $this->withToken($expired)->getJson('/ptadmin/session')->assertUnauthorized()->assertJsonPath('code', 40101);
        $revoked = $this->token();
        $sessions->revoke($revoked);
        $this->withToken($revoked)->getJson('/ptadmin/session')->assertUnauthorized()->assertJsonPath('code', 40101);
        $token = $this->token();
        $this->admin->update(['status' => 0]);
        $this->withToken($token)->getJson('/ptadmin/session')->assertUnauthorized()->assertJsonPath('code', 40102);
        $this->admin->update(['status' => 1]);
        $this->admin->delete();
        $this->withToken($token)->getJson('/ptadmin/session')->assertUnauthorized()->assertJsonPath('code', 40102);
    }

    public function testRevocationInvalidatesCurrentUserAndSchemaSubjectImmediately(): void
    {
        $sessions = app(SessionManager::class);
        foreach (['token', 'subject', 'id'] as $method) {
            $token = $this->token();
            $this->bindRequest($token);
            $guard = app(AuthService::class)->guard();
            $session = $guard->session();
            app(SchemaSubjectContextInterface::class)->set($guard->user());
            $other = $this->token();
            $sessions->revoke($other);
            self::assertTrue(AdminAuth::check());
            if ($method === 'token') {
                $sessions->revoke($token);
            } elseif ($method === 'subject') {
                $sessions->revokeSubject(Admin::class, $this->admin->id, 'admin');
            } else {
                $sessions->revokeByIdForSubject($session['id'], Admin::class, $this->admin->id, 'admin');
            }
            self::assertNull(AdminAuth::user());
            self::assertNull(app(SchemaSubjectContextInterface::class)->get());
        }
    }

    public function testFacadeGateAndMiddlewareUseTheSameAuthorizationAndFreshGrants(): void
    {
        $this->grant('allow');
        $this->bindRequest($this->token());
        self::assertTrue(AdminAuth::can('dashboard.view'));
        self::assertTrue(AdminAuth::hasPerm('dashboard.view'));
        self::assertContains('dashboard.view', AdminAuth::permissions());
        self::assertTrue(Gate::forUser(AdminAuth::user())->allows('ptadmin.permission', 'dashboard.view'));
        AdminAuth::authorize('dashboard.view');
        $this->app['router']->get('/ptadmin/permission-probe', static fn () => response('allowed'))
            ->middleware(['ptadmin.session', 'ptadmin.permission:dashboard,view']);
        $this->withToken($this->token())->getJson('/ptadmin/permission-probe')->assertOk();

        $this->bindRequest($this->token());
        $this->grant('deny');
        self::assertFalse(AdminAuth::can('dashboard.view'));
        self::assertFalse(AdminAuth::hasPerm('dashboard.view'));
        self::assertFalse(Gate::forUser(AdminAuth::user())->allows('ptadmin.permission', 'dashboard.view'));
        $this->withToken($this->token())->getJson('/ptadmin/permission-probe')->assertForbidden()->assertJsonPath('code', 40300);
        $this->bindRequest($this->token());
        $this->expectException(AuthorizationException::class);
        AdminAuth::authorize('dashboard.view');
    }

    public function testRolesAndPermissionsRespectTheHostAuthorizationIdentity(): void
    {
        $role = DB::table('admin_roles')->insertGetId(['name' => '任职角色', 'code' => 'assignment', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $resource = DB::table('admin_resources')->where('resource_key', 'dashboard')->value('id');
        DB::table('admin_grants')->insert(['subject_type' => 'role', 'subject_id' => (string) $role,
            'resource_id' => $resource, 'effect' => 'allow', 'abilities_json' => '["view"]', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        $resolver = new class($role) implements AuthorizationIdentityResolverInterface {
            public bool $permitted = true;
            public function __construct(private readonly int $role) {}
            public function resolve(Admin $admin): AuthorizationIdentity { return new AuthorizationIdentity([$this->role], directGrants: false); }
            public function permits(Admin $admin, string $resourceKey, string $ability): bool { return $this->permitted; }
        };
        $this->app->instance(AuthorizationIdentityResolverInterface::class, $resolver);
        $this->app->forgetInstance(AuthorizationService::class);
        $this->bindRequest($this->token());
        self::assertSame([['id' => $role, 'code' => 'assignment', 'title' => '任职角色']], AdminAuth::roles());
        self::assertTrue(AdminAuth::hasRole('assignment'));
        self::assertTrue(AdminAuth::anyRole('assignment'));
        self::assertTrue(AdminAuth::anyRole(['editor', 'assignment']));
        self::assertFalse(AdminAuth::anyRole(['editor', 'reviewer']));
        self::assertFalse(AdminAuth::anyRole(['Assignment', '*']));
        self::assertFalse(AdminAuth::anyRole([]));
        self::assertFalse(AdminAuth::hasRole('*'));
        self::assertTrue(AdminAuth::can('dashboard.view'));
        $resolver->permitted = false;
        self::assertFalse(AdminAuth::can('dashboard.view'));
        self::assertFalse(AdminAuth::hasPerm('dashboard.view'));
        self::assertSame([], AdminAuth::permissions());
        self::assertFalse(Gate::forUser(AdminAuth::user())->allows('ptadmin.permission', 'dashboard.view'));

        DB::table('admin_roles')->where('id', $role)->update(['status' => 0]);
        self::assertFalse(AdminAuth::anyRole(['editor', 'assignment']));
        DB::table('admin_roles')->where('id', $role)->update(['status' => 1, 'deleted_at' => now()]);
        self::assertFalse(AdminAuth::anyRole('assignment'));
    }

    public function testFrontendIdentityAndPoliciesRemainIndependent(): void
    {
        $member = new \PTAdmin\User\Models\User();
        $member->id = 900;
        Auth::guard('web')->setUser($member);
        $this->bindRequest($this->token());
        Gate::define('member-operation', static fn ($user): bool => $user === $member);
        self::assertSame('web', Auth::getDefaultDriver());
        self::assertSame($member, request()->user());
        self::assertTrue(Gate::allows('member-operation'));
        self::assertFalse(Gate::allows('ptadmin.permission', 'dashboard.view'));
        self::assertSame($this->admin->id, request()->user('ptadmin')->id);
        self::assertSame($member, Auth::user());
    }

    public function testAuthenticationAndAuthorizationExceptionsUseTheAdminEnvelope(): void
    {
        $this->app['router']->get('/ptadmin/auth-exception', static function () {
            AdminAuth::authorize('dashboard.view');
            return response('unexpected');
        });
        $this->getJson('/ptadmin/auth-exception')->assertUnauthorized()->assertJsonPath('code', 40101)->assertHeader('X-Request-ID');
        $this->withToken($this->token())->getJson('/ptadmin/auth-exception')->assertForbidden()->assertJsonPath('code', 40300)->assertHeader('X-Request-ID');
    }

    public function testMalformedPermissionIsNotSilentlyTreatedAsDenied(): void
    {
        foreach (['', 'dashboard', 'dashboard.*', '*.view', 'dashboard.access', 'dashboard.view.extra*', 'cms..document.view', 'cms.document..view', '.cms.view'] as $permission) {
            try {
                AdminAuth::can($permission);
                self::fail('Expected invalid permission: '.$permission);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('权限编码', $exception->getMessage());
            }
        }
    }

    public function testDatabaseFailureIsNotReportedAsAnonymous(): void
    {
        $this->bindRequest($this->token());
        DB::statement('DROP TABLE ptadmin_sessions');

        $this->expectException(\Illuminate\Database\QueryException::class);
        AdminAuth::user();
    }

    public function testHasPermRequiresAnOperationPermissionInsteadOfARoleCode(): void
    {
        $this->bindRequest($this->token());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('权限编码');
        AdminAuth::hasPerm('editor');
    }

    public function testMissingHostGuardConfigurationDoesNotFallBackToWeb(): void
    {
        config(['auth.guards.ptadmin' => null]);
        Auth::forgetGuards();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [ptadmin] is not defined.');
        AdminAuth::user();
    }

    private function token(?Admin $admin = null): string
    {
        return app(SessionManager::class)->issue(Admin::class, ($admin ?? $this->admin)->id, 'admin')->toArray()['access_token'];
    }

    private function bindRequest(?string $token = null): void
    {
        $request = Request::create('/ptadmin/guard-test');
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }
        $this->app->instance('request', $request);
    }

    private function grant(string $effect): void
    {
        DB::table('admin_grants')->insert(['subject_type' => 'admin', 'subject_id' => (string) $this->admin->id,
            'resource_id' => DB::table('admin_resources')->where('resource_key', 'dashboard')->value('id'),
            'effect' => $effect, 'abilities_json' => '["view"]', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertAuthenticationQueries(int $admins, int $sessions, int $updates): void
    {
        $queries = array_column(DB::getQueryLog(), 'query');
        self::assertCount($admins, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'select') && str_contains($sql, 'from "admins"')));
        self::assertCount($sessions, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'select') && str_contains($sql, 'from "ptadmin_sessions"')));
        self::assertCount($updates, array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'update "ptadmin_sessions"')));
    }
}
