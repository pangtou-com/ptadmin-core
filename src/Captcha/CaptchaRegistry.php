<?php

declare(strict_types=1);

namespace PTAdmin\Captcha;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Addon\Runtime\AddonRegistration;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, ProtocolException};

/** 每个插件注册一个验证码提供者，归属与提交原子性由插件启动作用域管理。 */
final class CaptchaRegistry
{
    /** @var array<string, array{class: class-string<CaptchaInterface>, token: object}> */
    private array $providers = [];

    public function __construct(
        private readonly Container $container,
        private readonly AddonAvailability $availability,
        private readonly AddonRegistration $registration,
    ) {
    }

    public function register(string $addonCode, string $provider): void
    {
        $this->availability->assertActive($addonCode);
        $this->registration->assertOwner($addonCode);
        if (!is_a($provider, CaptchaInterface::class, true)) {
            throw new ProtocolException('definition_invalid', '验证码提供者未实现协议：'.$addonCode);
        }
        $definition = $this->container->make($provider)->definition();
        foreach (['create', 'verify', 'refresh'] as $operation) {
            if (!$definition->supports($operation)) {
                throw new ProtocolException('definition_invalid', '验证码提供者缺少操作：'.$addonCode.'/'.$operation);
            }
        }
        $entry = ['class' => $provider, 'token' => new \stdClass()];
        $this->registration->stage($addonCode, self::class.':'.spl_object_id($this),
            function () use ($addonCode, $entry): void {
                if (isset($this->providers[$addonCode])) {
                    throw new ProtocolException('definition_conflict', '验证码插件重复注册：'.$addonCode);
                }
                $this->providers[$addonCode] = $entry;
            },
            function () use ($addonCode, $entry): void {
                if (($this->providers[$addonCode] ?? null) === $entry) {
                    unset($this->providers[$addonCode]);
                }
            },
        );
    }

    public function provider(string $addonCode): CaptchaInterface
    {
        $this->availability->assertActive($addonCode);
        $class = $this->providers[$addonCode]['class'] ?? null;
        if ($class === null) {
            throw new ProtocolException('reference_missing', '验证码插件未注册：'.$addonCode);
        }
        return $this->container->make($class);
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $result = [];
        foreach ($this->providers as $code => $entry) {
            if ($this->availability->active($code)) {
                $result[$code] = $this->container->make($entry['class'])->definition()->toArray();
            }
        }
        return $result;
    }
}
