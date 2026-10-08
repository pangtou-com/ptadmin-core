<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Runtime;

use Illuminate\Foundation\Application;
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\ProtocolTestCase;
use PTAdmin\Core\Providers\ServiceProtocolServiceProvider;
use PTAdmin\Addon\Runtime\{AddonAutoloader, AddonRegistration, RuntimeRegistrar};
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Payment\{PaymentRegistry, PaymentGateway, PaymentRequirements, PaymentTarget};
use PTAdmin\SocialAuth\{SocialAuthRegistry, SocialAuthGateway, SocialAuthRequirements};

final class AddonCapabilityRegistrationTest extends ProtocolTestCase
{
    private PaymentRegistry $payments;
    private SocialAuthRegistry $auth;
    private Application $app;
    private RuntimeRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application($this->temporary);
        $this->app->instance(LifecycleStore::class, $this->lifecycle);
        $this->app->singleton(AddonRegistration::class);
        $this->app->register(ServiceProtocolServiceProvider::class);
        $this->payments = $this->app->make(PaymentRegistry::class);
        $this->auth = $this->app->make(SocialAuthRegistry::class);
        $this->registrar = new RuntimeRegistrar($this->app,
            new AddonCatalog($this->temporary.'/addons', new ManifestVerifier(), $this->lifecycle, false),
            $this->lifecycle, $this->temporary.'/addons');
    }

    /** @return array{string,string} */
    private function providers(bool $invalidAuth = false): array
    {
        $namespace = 'FixtureProtocol'.bin2hex(random_bytes(6));
        $directory = $this->temporary.'/addons/provider-plugin';
        mkdir($directory.'/src/Services', 0700, true);
        mkdir($directory.'/src/Providers', 0700, true);
        $payment = <<<'PHP'
<?php
namespace NAMESPACE\Services;

use PTAdmin\Payment\Contracts\PaymentProviderInterface;
use PTAdmin\Payment\{PaymentRegistration, PaymentDefinition, PaymentSceneDefinition, PaymentScene, PaymentSupport,
    PaymentTarget, PaymentInteractionDefinition, PaymentInteractionType, PaymentOperation, PaymentProfile};
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\FakePaymentAdapter;

final class PaymentChannels implements PaymentProviderInterface
{
    public function register(PaymentRegistration $registration): void
    {
        foreach (['alipay', 'wechat_pay'] as $channel) {
            $registration->add(new PaymentDefinition($channel, $channel, [new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [
                new PaymentSupport('pc_qr', PaymentTarget::Pc, new PaymentInteractionDefinition(PaymentInteractionType::QrCode),
                    [PaymentOperation::Create, PaymentOperation::Query, PaymentOperation::ParseNotify, PaymentOperation::AcknowledgeNotify], ['CNY']),
            ])]), FakePaymentAdapter::class, [new PaymentProfile('default', 'merchant-original')]);
        }
    }
}
PHP;
        $auth = $invalidAuth ? <<<'PHP'
<?php
namespace NAMESPACE\Services;
final class LoginChannels {}
PHP
        : <<<'PHP'
<?php
namespace NAMESPACE\Services;

use PTAdmin\SocialAuth\Contracts\SocialAuthProviderInterface;
use PTAdmin\SocialAuth\{SocialAuthRegistration, SocialAuthDefinition, SocialAuthProfile};
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\FakeSocialAuthAdapter;

final class LoginChannels implements SocialAuthProviderInterface
{
    public function register(SocialAuthRegistration $registration): void
    {
        $registration->add(new SocialAuthDefinition('oauth', '网站登录'), FakeSocialAuthAdapter::class,
            [new SocialAuthProfile('default', 'issuer:app-original')]);
    }
}
PHP;
        file_put_contents($directory.'/src/Services/PaymentChannels.php', str_replace('NAMESPACE', $namespace, $payment));
        file_put_contents($directory.'/src/Services/LoginChannels.php', str_replace('NAMESPACE', $namespace, $auth));
        $provider = <<<'PHP'
<?php
namespace NAMESPACE\Providers;

use Illuminate\Support\ServiceProvider as LaravelProvider;
use PTAdmin\Addon\Runtime\Contracts\CapabilityProviderInterface;
use PTAdmin\Payment\PaymentRegistry;
use PTAdmin\SocialAuth\SocialAuthRegistry;
use PTAdmin\Addon\Runtime\AddonRegistration;
use NAMESPACE\Services\{PaymentChannels, LoginChannels};

final class ServiceProvider extends LaravelProvider implements CapabilityProviderInterface
{
    public function register(): void
    {
        $this->app->instance('fixture.services.prepared', true);
    }

    public function registerCapabilities(string $addonCode): void
    {
        $this->app->make(PaymentRegistry::class)->register($addonCode, new PaymentChannels());
        $this->app->make(SocialAuthRegistry::class)->register($addonCode, new LoginChannels());
        if ($this->app->bound('fixture.duplicate')) {
            $this->app->make(PaymentRegistry::class)->register($addonCode, new PaymentChannels());
        }
        // 模拟插件主动注册自有驱动，不需要启动器认识该能力类型。
        $scope = $this->app->make(AddonRegistration::class);
        $scope->stage($addonCode, 'fixture.storage',
            fn () => $this->app->instance('fixture.storage', 'custom-driver'),
            function (): void {
                $this->app->forgetInstance('fixture.storage');
                if ($this->app->bound('fixture.rollback.fail')) {
                    throw new \LogicException('驱动撤销失败');
                }
            },
        );
        if ($this->app->bound('fixture.commit.fail')) {
            $scope->stage($addonCode, 'fixture.failure',
                static function (): void { throw new \LogicException('驱动提交失败'); },
                static function (): void {},
            );
        }
    }
}
PHP;
        file_put_contents($directory.'/src/Providers/ServiceProvider.php', str_replace('NAMESPACE', $namespace, $provider));
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'provider-plugin', 'version' => '1.0.0', 'namespace' => $namespace,
        ], JSON_THROW_ON_ERROR));
        return [$directory, $namespace];
    }

    public function testPluginActivelyRegistersMultipleDomainsWithVerifiedOwnerAndNoDuplicates(): void
    {
        $this->providers();
        $this->activate('provider-plugin');
        $result = $this->registrar->register();
        self::assertSame([], $result['errors']);
        self::assertSame(['provider-plugin'], $result['loaded']);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
        self::assertCount(1, $this->auth->definitions()['provider-plugin']);
        $candidates = $this->payments->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY'));
        self::assertCount(2, $candidates);
        self::assertSame('provider-plugin', $candidates[0]->reference->addonCode);
        self::assertCount(1, $this->auth->discover(new SocialAuthRequirements()));
        self::assertSame([], $this->registrar->register()['errors']);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
    }

    public function testInvalidSecondDomainLeavesNoChannelsAndMarksPluginInactive(): void
    {
        $this->providers(true);
        $this->activate('provider-plugin');
        $result = $this->registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertCount(1, $result['errors']);
        self::assertSame('inactive', $this->lifecycle->read('provider-plugin')['state']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
    }

    public function testPreparationSkipsCapabilitiesForActiveAndInactivePluginsAndLaterStartupRegistersThem(): void
    {
        [$directory] = $this->providers();
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['inactive', 'active'] as $state) {
            $this->lifecycle->transition('provider-plugin', $state);
            $this->registrar->prepareServices($directory, $manifest);
            self::assertTrue($this->app->make('fixture.services.prepared'));
            self::assertSame($state, $this->lifecycle->read('provider-plugin')['state']);
            self::assertSame([], $this->payments->definitions());
            self::assertSame([], $this->auth->definitions());
        }
        self::assertSame([], $this->registrar->register()['errors']);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
    }

    public function testDuplicateDomainRegistrationLeavesNoPartialChannels(): void
    {
        $this->providers();
        $this->activate('provider-plugin');
        $this->app->instance('fixture.duplicate', true);
        $result = $this->registrar->register();
        self::assertSame('capability_registration_conflict', $result['errors'][0]['error_code']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
    }

    public function testCustomCapabilityCommitFailureRollsBackAllDomainsAndCanRetry(): void
    {
        $this->providers();
        $this->activate('provider-plugin');
        $this->app->instance('fixture.commit.fail', true);
        $result = $this->registrar->register();
        self::assertSame('驱动提交失败', $result['errors'][0]['message']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
        self::assertFalse($this->app->bound('fixture.storage'));
        $this->app->forgetInstance('fixture.commit.fail');
        self::assertSame([], $this->registrar->register()['errors']);
        self::assertSame('custom-driver', $this->app->make('fixture.storage'));
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
        self::assertCount(1, $this->auth->definitions()['provider-plugin']);
    }

    public function testFailureInAnotherPluginProviderDiscardsFirstProvidersDeclarations(): void
    {
        [$directory, $namespace] = $this->providers();
        $second = <<<'PHP'
<?php
namespace NAMESPACE\Providers;

use Illuminate\Support\ServiceProvider;
use PTAdmin\Addon\Runtime\Contracts\CapabilityProviderInterface;

final class FailingProvider extends ServiceProvider implements CapabilityProviderInterface
{
    public function registerCapabilities(string $addonCode): void
    {
        throw new \LogicException('第二个 Provider 注册失败');
    }
}
PHP;
        file_put_contents($directory.'/src/Providers/FailingProvider.php', str_replace('NAMESPACE', $namespace, $second));
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['providers'] = [
            ['file' => 'src/Providers/ServiceProvider.php', 'class' => $namespace.'\\Providers\\ServiceProvider'],
            ['file' => 'src/Providers/FailingProvider.php', 'class' => $namespace.'\\Providers\\FailingProvider'],
        ];
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->activate('provider-plugin');
        $result = $this->registrar->register();
        self::assertSame('第二个 Provider 注册失败', $result['errors'][0]['message']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
        self::assertFalse($this->app->bound('fixture.storage'));
    }

    public function testRollbackFailureStillCleansOtherDomainsAndRecordsFailure(): void
    {
        $this->providers();
        $this->activate('provider-plugin');
        $this->app->instance('fixture.commit.fail', true);
        $this->app->instance('fixture.rollback.fail', true);
        $result = $this->registrar->register();
        self::assertSame('capability_rollback_failed', $result['errors'][0]['error_code']);
        self::assertStringContainsString('驱动提交失败', $result['errors'][0]['message']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
        self::assertFalse($this->app->bound('fixture.storage'));
    }

    public function testDisabledPluginProviderIsNeverExecuted(): void
    {
        [$directory] = $this->providers();
        $this->lifecycle->transition('provider-plugin', 'inactive', ['disabled_by_user' => true]);
        file_put_contents($directory.'/src/Providers/ServiceProvider.php', '<?php throw new \\LogicException("不应加载停用插件");');
        self::assertSame(['loaded' => [], 'errors' => []], $this->registrar->register());
        self::assertSame([], $this->payments->definitions());
    }

    public function testProtocolFilenamesAloneDoNotExecuteAnyCode(): void
    {
        $directory = $this->temporary.'/addons/provider-plugin';
        mkdir($directory.'/src/Runtime', 0700, true);
        file_put_contents($directory.'/manifest.json', json_encode(['code' => 'provider-plugin', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        foreach (['PaymentProvider', 'SocialAuthProvider'] as $name) {
            file_put_contents($directory.'/src/Runtime/'.$name.'.php', '<?php throw new \\LogicException("不应扫描协议文件");');
        }
        $this->activate('provider-plugin');
        self::assertSame(['loaded' => ['provider-plugin'], 'errors' => []], $this->registrar->register());
        self::assertSame([], $this->payments->definitions());
    }

    public function testEarlierStartupFailureDoesNotCallCapabilityRegistration(): void
    {
        [$directory] = $this->providers();
        mkdir($directory.'/config');
        file_put_contents($directory.'/config/config.php', '<?php return false;');
        $this->activate('provider-plugin');
        $result = $this->registrar->register();
        self::assertSame('invalid_config', $result['errors'][0]['error_code']);
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
    }

    public function testRegistrationRejectsAnotherOwnerAndCallsOutsideStartupScope(): void
    {
        [$directory, $namespace] = $this->providers();
        $autoloader = new AddonAutoloader();
        $autoloader->register($directory, ['code' => 'provider-plugin', 'namespace' => $namespace]);
        $class = $namespace.'\\Services\\PaymentChannels';
        $provider = new $class();
        $this->activate('provider-plugin');
        $this->activate('another-plugin');
        foreach ([null, 'another-plugin'] as $owner) {
            try {
                $action = fn () => $this->payments->register('provider-plugin', $provider);
                $owner === null ? $action() : $this->app->make(AddonRegistration::class)->run($owner, $action);
                self::fail('预期拒绝错误的注册归属。');
            } catch (AddonException $exception) {
                self::assertSame('capability_owner_invalid', $exception->errorCode);
            }
        }
        self::assertSame([], $this->payments->definitions());
        $this->lifecycle->transition('provider-plugin', 'inactive');
        $this->assertProtocolError('addon_unavailable', fn () => $this->payments->register('provider-plugin', $provider));
    }

    public function testLaravelProviderRegistersSharedDomainServicesWithoutChannels(): void
    {
        self::assertSame($this->payments, $this->app->make(PaymentRegistry::class));
        self::assertSame($this->auth, $this->app->make(SocialAuthRegistry::class));
        self::assertInstanceOf(PaymentGateway::class, $this->app->make(PaymentGateway::class));
        self::assertInstanceOf(SocialAuthGateway::class, $this->app->make(SocialAuthGateway::class));
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
    }
}
