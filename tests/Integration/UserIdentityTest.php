<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PTAdmin\User\Contracts\UserIdentityInterface;
use PTAdmin\User\Events\UserUnavailable;
use PTAdmin\User\UserServiceProvider;
use PTAdmin\User\Models\User;

final class UserIdentityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UserServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pt_']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('auth', [
            'defaults' => ['guard' => 'web', 'passwords' => 'users'],
            'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
            'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
            'passwords' => ['users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60]],
        ]);
    }

    private function migrateUsers(): void
    {
        (require __DIR__.'/../../database/migrations/2026_11_11_130001_create_member_tables.php')->up();
    }

    public function testCoreAloneProvidesUserAuthenticationAndBatchIdentityQueries(): void
    {
        self::assertSame([], $this->app['db']->getConnections());
        $identity = $this->app->make(UserIdentityInterface::class);
        self::assertSame([], $this->app['db']->getConnections());
        $this->migrateUsers();
        $active = User::create(['name' => '有效会员', 'email' => 'active@example.test', 'password' => 'secret-password'])->refresh();
        $inactive = User::create(['name' => '停用会员', 'email' => 'inactive@example.test', 'password' => 'other-password'])->refresh();
        $inactive->status = false;
        $inactive->save();
        self::assertTrue(Hash::check('secret-password', $active->password));
        self::assertArrayNotHasKey('password', $active->toArray());
        self::assertTrue(Auth::guard('web')->attempt(['email' => $active->email, 'password' => 'secret-password', 'status' => true]));
        self::assertSame($active->id, $identity->currentId());
        self::assertSame(['id' => $active->id, 'name' => '有效会员', 'active' => true, 'phone_verified' => false, 'realname_verified' => false], $identity->find($active->id));
        self::assertNull($identity->find(999));
        self::assertSame([['id' => $active->id, 'name' => '有效会员']], $identity->search('会员'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertCount(2, $identity->profiles([$active->id, $inactive->id]));
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        self::assertSame([['id' => $inactive->id, 'name' => '停用会员']], $identity->profiles([$active->id, $inactive->id], '停用', 1));
        $query = DB::table('users');
        $identity->constrainActive($query, 'id');
        self::assertSame([$active->id], $query->pluck('id')->all());
        $broker = $this->app['auth.password']->broker('users');
        self::assertTrue($broker->tokenExists($active, $broker->createToken($active)));
    }

    public function testIndependentConsumersReceiveGenericUserUnavailableEvents(): void
    {
        $this->migrateUsers();
        $firstPlugin = [];
        $secondPlugin = [];
        $this->app['events']->listen(UserUnavailable::class, static function (UserUnavailable $event) use (&$firstPlugin): void {
            $firstPlugin[] = $event->userId;
        });
        $this->app['events']->listen(UserUnavailable::class, static function (UserUnavailable $event) use (&$secondPlugin): void {
            $secondPlugin[] = $event->userId;
        });
        $user = User::create(['name' => '会员', 'email' => 'member@example.test', 'password' => 'secret-password'])->refresh();
        $user->name = '修改昵称';
        $user->save();
        self::assertSame([], $firstPlugin);
        $user->status = false;
        $user->save();
        self::assertSame([$user->id], $firstPlugin);
        $user->status = true;
        $user->save();
        self::assertSame([$user->id], $firstPlugin);
        $user->delete();
        self::assertSame([$user->id, $user->id], $firstPlugin);
        self::assertSame($firstPlugin, $secondPlugin);
    }

    public function testHostModelAndWebIdentityRemainIndependentOfTheDefaultGuard(): void
    {
        $this->migrateUsers();
        Schema::rename('users', 'host_users');
        config([
            'auth.providers.users.model' => HostIdentityUser::class,
            'auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'],
        ]);
        $user = HostIdentityUser::create(['name' => '前台用户', 'email' => 'user@example.test', 'password' => 'secret-password'])->refresh();
        $other = HostIdentityUser::create(['name' => '另一身份', 'email' => 'other@example.test', 'password' => 'other-password'])->refresh();
        Auth::guard('web')->login($user);
        Auth::guard('admin')->login($other);
        Auth::shouldUse('admin');

        $identity = $this->app->make(UserIdentityInterface::class);
        self::assertSame($other->id, Auth::id());
        self::assertSame($user->id, $identity->currentId());
        self::assertSame('前台用户', $identity->find($user->id)['name']);
        self::assertSame([['id' => $user->id, 'name' => '前台用户']], $identity->search('前台'));
        self::assertSame([['id' => $user->id, 'name' => '前台用户']], $identity->profiles([$user->id]));
        Auth::guard('web')->logout();
        self::assertNull($identity->currentId());
        self::assertSame($other->id, Auth::id());
    }

    public function testUserMigrationPreservesExistingCustomHostTablesAndRecords(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('nickname');
        });
        Schema::create('password_reset_tokens', static function (Blueprint $table): void {
            $table->string('custom_token');
        });
        DB::table('users')->insert(['id' => 42, 'nickname' => '既有会员']);
        DB::table('password_reset_tokens')->insert(['custom_token' => 'retained']);
        $migration = require __DIR__.'/../../database/migrations/2026_11_11_130001_create_member_tables.php';
        $migration->up();
        $migration->down();
        self::assertSame('既有会员', DB::table('users')->where('id', 42)->value('nickname'));
        self::assertSame(['id', 'nickname'], Schema::getColumnListing('users'));
        self::assertSame('retained', DB::table('password_reset_tokens')->value('custom_token'));
    }

    public function testRepeatedMigrationAndRollbackPreserveDefaultUsers(): void
    {
        $this->migrateUsers();
        $user = User::create(['name' => '会员', 'email' => 'retained@example.test', 'password' => 'secret-password']);
        $this->migrateUsers();
        (require __DIR__.'/../../database/migrations/2026_11_11_130001_create_member_tables.php')->down();
        self::assertSame($user->id, User::query()->sole()->id);
    }
}

final class HostIdentityUser extends User
{
    protected $table = 'host_users';
}
