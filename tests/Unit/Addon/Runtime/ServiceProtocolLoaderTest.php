<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Runtime;

use Illuminate\Foundation\Application;
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\ProtocolTestCase;
use PTAdmin\Core\Providers\ServiceProtocolServiceProvider;
use PTAdmin\Core\ServiceProtocol\AddonAvailability;
use PTAdmin\Addon\Runtime\{ServiceProtocolLoader, RuntimeRegistrar};
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Payment\{PaymentRegistry, PaymentGateway, PaymentRequirements, PaymentTarget};
use PTAdmin\SocialAuth\{SocialAuthRegistry, SocialAuthGateway, SocialAuthRequirements};

final class ServiceProtocolLoaderTest extends ProtocolTestCase
{
    private PaymentRegistry $payments;
    private SocialAuthRegistry $auth;
    private ServiceProtocolLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = new PaymentRegistry($this->container, $this->availability);
        $this->auth = new SocialAuthRegistry($this->container, $this->availability);
        $this->loader = new ServiceProtocolLoader($this->container, $this->payments, $this->auth, $this->availability);
    }

    /** @return array{string,string} */
    private function providers(bool $invalidAuth = false): array
    {
        $namespace = 'FixtureProtocol'.bin2hex(random_bytes(6));
        $directory = $this->temporary.'/addons/provider-plugin';
        mkdir($directory.'/src/Runtime', 0700, true);
        $payment = <<<'PHP'
<?php
namespace NAMESPACE\Runtime;

use PTAdmin\Payment\Contracts\PaymentProviderInterface;
use PTAdmin\Payment\{PaymentRegistration, PaymentDefinition, PaymentSceneDefinition, PaymentScene, PaymentSupport,
    PaymentTarget, PaymentInteractionDefinition, PaymentInteractionType, PaymentOperation, PaymentProfile};
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\FakePaymentAdapter;

final class PaymentProvider implements PaymentProviderInterface
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
namespace NAMESPACE\Runtime;
final class SocialAuthProvider {}
PHP
        : <<<'PHP'
<?php
namespace NAMESPACE\Runtime;

use PTAdmin\SocialAuth\Contracts\SocialAuthProviderInterface;
use PTAdmin\SocialAuth\{SocialAuthRegistration, SocialAuthDefinition, SocialAuthProfile};
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\FakeSocialAuthAdapter;

final class SocialAuthProvider implements SocialAuthProviderInterface
{
    public function register(SocialAuthRegistration $registration): void
    {
        $registration->add(new SocialAuthDefinition('oauth', '网站登录'), FakeSocialAuthAdapter::class,
            [new SocialAuthProfile('default', 'issuer:app-original')]);
    }
}
PHP;
        file_put_contents($directory.'/src/Runtime/PaymentProvider.php', str_replace('NAMESPACE', $namespace, $payment));
        file_put_contents($directory.'/src/Runtime/SocialAuthProvider.php', str_replace('NAMESPACE', $namespace, $auth));
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'provider-plugin', 'version' => '1.0.0', 'namespace' => $namespace,
        ], JSON_THROW_ON_ERROR));
        return [$directory, $namespace];
    }

    public function testLoaderBindsVerifiedOwnerForMultipleDomainsAndDoesNotDuplicateRegistration(): void
    {
        [$directory, $namespace] = $this->providers();
        $this->activate('provider-plugin');
        $this->loader->register($directory, 'provider-plugin', $namespace);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
        self::assertCount(1, $this->auth->definitions()['provider-plugin']);
        $candidates = $this->payments->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY'));
        self::assertCount(2, $candidates);
        self::assertSame('provider-plugin', $candidates[0]->reference->addonCode);
        $this->loader->register($directory, 'provider-plugin', $namespace);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
    }

    public function testInvalidSecondDomainLeavesNoPartiallyRegisteredPaymentChannels(): void
    {
        [$directory, $namespace] = $this->providers(true);
        $this->activate('provider-plugin');
        $this->assertProtocolError('provider_invalid', fn () => $this->loader->register($directory, 'provider-plugin', $namespace));
        self::assertSame([], $this->payments->definitions());
        self::assertSame([], $this->auth->definitions());
    }

    public function testDisabledProviderFilesAreNeverExecuted(): void
    {
        [$directory, $namespace] = $this->providers();
        $this->lifecycle->transition('provider-plugin', 'inactive');
        file_put_contents($directory.'/src/Runtime/PaymentProvider.php', '<?php throw new \\LogicException("不应加载停用插件");');
        $this->assertProtocolError('addon_unavailable', fn () => $this->loader->register($directory, 'provider-plugin', $namespace));
        self::assertSame([], $this->payments->definitions());
    }

    public function testLaravelProviderRegistersSharedSingletonsWithoutAnyChannels(): void
    {
        $app = new Application($this->temporary);
        $app->instance(LifecycleStore::class, $this->lifecycle);
        $app->register(ServiceProtocolServiceProvider::class);
        self::assertSame($app->make(PaymentRegistry::class), $app->make(PaymentRegistry::class));
        self::assertSame($app->make(SocialAuthRegistry::class), $app->make(SocialAuthRegistry::class));
        self::assertInstanceOf(PaymentGateway::class, $app->make(PaymentGateway::class));
        self::assertInstanceOf(SocialAuthGateway::class, $app->make(SocialAuthGateway::class));
        self::assertSame([], $app->make(PaymentRegistry::class)->definitions());
    }

    public function testActualRuntimeRegistrarLoadsProvidersFromVerifiedPluginNamespace(): void
    {
        [$directory, $namespace] = $this->providers();
        $app = new Application($this->temporary);
        $app->instance(ServiceProtocolLoader::class, $this->loader);
        $registrar = new RuntimeRegistrar($app,
            new AddonCatalog($this->temporary.'/addons', new ManifestVerifier(), $this->lifecycle, false),
            $this->lifecycle, $this->temporary.'/addons');
        $result = $registrar->register();
        self::assertSame([], $result['errors']);
        self::assertSame(['provider-plugin'], $result['loaded']);
        self::assertSame($namespace, json_decode(file_get_contents($directory.'/manifest.json'), true)['namespace']);
        self::assertCount(2, $this->payments->definitions()['provider-plugin']);
        self::assertCount(1, $this->auth->discover(new SocialAuthRequirements()));
    }

    public function testRuntimeFailureMarksPluginInactiveAndDoesNotPublishPartialChannels(): void
    {
        $this->providers(true);
        $app = new Application($this->temporary);
        $app->instance(ServiceProtocolLoader::class, $this->loader);
        $registrar = new RuntimeRegistrar($app,
            new AddonCatalog($this->temporary.'/addons', new ManifestVerifier(), $this->lifecycle, false),
            $this->lifecycle, $this->temporary.'/addons');
        $result = $registrar->register();
        self::assertSame([], $result['loaded']);
        self::assertCount(1, $result['errors']);
        self::assertSame('inactive', $this->lifecycle->read('provider-plugin')['state']);
        self::assertSame([], $this->payments->definitions());
    }
}
