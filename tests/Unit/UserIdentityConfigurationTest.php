<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use PTAdmin\User\Contracts\UserIdentityInterface;
use PTAdmin\User\UserServiceProvider;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserIdentityConfigurationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->app = new Application($root);
        $this->app->instance('config', new Repository([
            'auth' => ['guards' => ['web' => ['provider' => 'users']], 'providers' => []],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        Facade::setFacadeApplication($this->app);
    }

    public function testRegistrationPreservesHostAuthenticationConfiguration(): void
    {
        $auth = $this->app['config']->get('auth');
        (new UserServiceProvider($this->app))->register();
        self::assertSame($auth, $this->app['config']->get('auth'));
        self::assertFalse($this->app->resolved('db'));
    }

    public static function invalidProviders(): array
    {
        return [
            'database provider without model' => [['driver' => 'database', 'table' => 'users']],
            'missing provider' => [null],
            'unknown class' => [['driver' => 'eloquent', 'model' => 'MissingUser']],
            'not an Eloquent model' => [['driver' => 'eloquent', 'model' => \stdClass::class]],
        ];
    }

    #[DataProvider('invalidProviders')]
    public function testMissingIdentityFailsWithAnActionableConfigurationError(?array $providerConfig): void
    {
        $this->app['config']->set('auth.providers.users', $providerConfig);
        $provider = new UserServiceProvider($this->app);
        $provider->register();
        self::assertSame($providerConfig, $this->app['config']->get('auth.providers.users'));
        try {
            $provider->boot();
            self::fail('Invalid identity configuration must stop plugin startup.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('UserIdentityInterface', $exception->getMessage());
            self::assertStringContainsString('config/auth.php', $exception->getMessage());
            self::assertFalse($this->app->resolved('db'));
        }
    }

    public function testEloquentIdentityRegistersAccountLifecycleListenersWithoutQuerying(): void
    {
        $this->app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => UserIdentityUser::class]);
        $events = new Dispatcher($this->app);
        Model::setEventDispatcher($events);
        $provider = new UserServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        self::assertTrue($events->hasListeners('eloquent.updated: '.UserIdentityUser::class));
        self::assertTrue($events->hasListeners('eloquent.deleted: '.UserIdentityUser::class));
        self::assertFalse($this->app->resolved('db'));
    }

    public function testCustomIdentityDoesNotRequireAnEloquentProvider(): void
    {
        $this->app['config']->set('auth', []);
        $identity = $this->createMock(UserIdentityInterface::class);
        $this->app->instance(UserIdentityInterface::class, $identity);
        $provider = new UserServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        self::assertSame($identity, $this->app->make(UserIdentityInterface::class));
        self::assertFalse($this->app->resolved('db'));
    }
}

final class UserIdentityUser extends Model
{
}
