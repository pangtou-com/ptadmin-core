<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, ProtocolException};
use PTAdmin\Payment\{PaymentRegistry, PaymentRegistration};
use PTAdmin\Payment\Contracts\PaymentProviderInterface;
use PTAdmin\SocialAuth\{SocialAuthRegistry, SocialAuthRegistration};
use PTAdmin\SocialAuth\Contracts\SocialAuthProviderInterface;

/** 在插件其余启动入口成功后加载；两个能力域全部通过校验后才提交。 */
final class ServiceProtocolLoader
{
    /** @var array<string,true> */
    private array $loaded = [];

    public function __construct(
        private Container $container,
        private PaymentRegistry $payments,
        private SocialAuthRegistry $socialAuth,
        private AddonAvailability $availability,
    ) {
    }

    /** @internal directory、addonCode 和 namespace 必须来自已验证的插件加载器。 */
    public function register(string $directory, string $addonCode, string $namespace): void
    {
        $this->availability->assertActive($addonCode);
        if (isset($this->loaded[$addonCode])) {
            return;
        }
        $paymentPath = $directory.'/runtime/PaymentProvider.php';
        $authPath = $directory.'/runtime/SocialAuthProvider.php';
        if (!is_file($paymentPath) && !is_file($authPath)) {
            return;
        }
        $payments = new PaymentRegistration();
        $auth = new SocialAuthRegistration();
        if (is_file($paymentPath)) {
            $class = $namespace.'\\Runtime\\PaymentProvider';
            if (!class_exists($class, false)) {
                require_once $paymentPath;
            }
            if (!is_a($class, PaymentProviderInterface::class, true)) {
                throw new ProtocolException('provider_invalid', '插件支付声明类必须实现 PaymentProviderInterface：'.$addonCode);
            }
            $this->container->make($class)->register($payments);
        }
        if (is_file($authPath)) {
            $class = $namespace.'\\Runtime\\SocialAuthProvider';
            if (!class_exists($class, false)) {
                require_once $authPath;
            }
            if (!is_a($class, SocialAuthProviderInterface::class, true)) {
                throw new ProtocolException('provider_invalid', '插件登录声明类必须实现 SocialAuthProviderInterface：'.$addonCode);
            }
            $this->container->make($class)->register($auth);
        }
        if (is_file($paymentPath)) {
            $this->payments->assertCanCommit($addonCode);
        }
        if (is_file($authPath)) {
            $this->socialAuth->assertCanCommit($addonCode);
        }
        if (is_file($paymentPath)) {
            $this->payments->commit($addonCode, $payments->channels());
        }
        if (is_file($authPath)) {
            $this->socialAuth->commit($addonCode, $auth->channels());
        }
        $this->loaded[$addonCode] = true;
    }
}
