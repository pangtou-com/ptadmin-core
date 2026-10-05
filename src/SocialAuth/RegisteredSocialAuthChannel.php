<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};
use PTAdmin\SocialAuth\Contracts\{SocialAuthAdapterInterface, RefreshableSocialAuthInterface};

final class RegisteredSocialAuthChannel
{
    /** @param SocialAuthAdapterInterface|string $adapter @param list<SocialAuthProfile> $profiles */
    public function __construct(
        public readonly SocialAuthDefinition $definition,
        private readonly SocialAuthAdapterInterface|string $adapter,
        public readonly array $profiles,
    ) {
        Input::instances($profiles, SocialAuthProfile::class, 'profiles');
        $codes = array_map(static fn (SocialAuthProfile $profile): string => $profile->code, $profiles);
        if (count($codes) !== count(array_unique($codes))) {
            throw new ProtocolException('definition_conflict', '登录配置档案编码重复：'.$definition->code);
        }
        if (!is_a($adapter, SocialAuthAdapterInterface::class, true)
            || ($definition->supportsRefresh && !is_a($adapter, RefreshableSocialAuthInterface::class, true))) {
            throw new ProtocolException('adapter_invalid', '登录声明与适配器契约不一致：'.$definition->code);
        }
    }

    public function resolve(Container $container): SocialAuthAdapterInterface
    {
        $adapter = is_string($this->adapter) ? $container->make($this->adapter) : $this->adapter;
        if (!$adapter instanceof SocialAuthAdapterInterface) {
            throw new ProtocolException('adapter_invalid', '登录容器解析结果不符合契约：'.$this->definition->code);
        }
        return $adapter;
    }

    public function profile(string $code): SocialAuthProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->code === $code) {
                return $profile;
            }
        }
        throw new ProtocolException('reference_missing', '登录配置档案未注册：'.$this->definition->code.'/'.$code);
    }
}
