<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Addon\Runtime\AddonRegistration;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, Input, ProtocolException};
use PTAdmin\SocialAuth\Contracts\SocialAuthAdapterInterface;
use PTAdmin\SocialAuth\Contracts\SocialAuthProviderInterface;

final class SocialAuthRegistry
{
    /** @var array<string,array<string,RegisteredSocialAuthChannel>> */
    private array $channels = [];

    public function __construct(private Container $container, private AddonAvailability $availability, private ?AddonRegistration $registration = null)
    {
    }

    /** 插件 Provider 主动提交登录声明，当前启动作用域绑定插件归属。 */
    public function register(string $addonCode, SocialAuthProviderInterface $provider): void
    {
        $this->availability->assertActive($addonCode);
        $scope = $this->registration ??= $this->container->make(AddonRegistration::class);
        $scope->assertOwner($addonCode);
        $this->assertCanCommit($addonCode);
        $channels = new SocialAuthRegistration();
        $provider->register($channels);
        $scope->stage($addonCode, self::class.':'.spl_object_id($this),
            fn () => $this->commit($addonCode, $channels->channels()),
            function () use ($addonCode, $channels): void {
                if (($this->channels[$addonCode] ?? null) === $channels->channels()) {
                    unset($this->channels[$addonCode]);
                }
            },
        );
    }

    /** @internal 提交前检查，业务插件使用 register()。 */
    public function assertCanCommit(string $addonCode): void
    {
        Input::code($addonCode, 'addon_code');
        if (isset($this->channels[$addonCode])) {
            throw new ProtocolException('definition_conflict', '插件登录声明已经注册：'.$addonCode);
        }
    }

    /** @internal @param array<string,RegisteredSocialAuthChannel> $channels */
    public function commit(string $addonCode, array $channels): void
    {
        $this->assertCanCommit($addonCode);
        $this->channels[$addonCode] = $channels;
    }

    /** @return array<string,array<string,SocialAuthDefinition>> */
    public function definitions(): array
    {
        $result = [];
        foreach ($this->channels as $owner => $channels) {
            $result[$owner] = array_map(static fn (RegisteredSocialAuthChannel $channel): SocialAuthDefinition => $channel->definition, $channels);
        }
        return $result;
    }

    /** @return list<SocialAuthCandidate> */
    public function discover(SocialAuthRequirements $requirements): array
    {
        $result = [];
        foreach ($this->channels as $owner => $channels) {
            if (($requirements->addonCode !== null && $requirements->addonCode !== $owner) || !$this->availability->active($owner)) {
                continue;
            }
            foreach ($channels as $code => $channel) {
                if (($requirements->channelCode !== null && $requirements->channelCode !== $code)
                    || ($requirements->requireRefresh && !$channel->definition->supportsRefresh)
                    || array_diff($requirements->scopes, $channel->definition->scopes) !== []) {
                    continue;
                }
                foreach ($channel->profiles as $profile) {
                    if ($requirements->profileCode !== null && $requirements->profileCode !== $profile->code) {
                        continue;
                    }
                    $reference = new SocialAuthReference($owner, $code, $profile->code, $profile->identityNamespace);
                    $readiness = $channel->resolve($this->container)->readiness($reference);
                    if ($readiness->ready && $readiness->identityNamespace !== $profile->identityNamespace) {
                        $readiness = new SocialAuthReadiness(false, $readiness->identityNamespace, 'identity_namespace_changed');
                    }
                    if ($requirements->availableOnly && !$readiness->ready) {
                        continue;
                    }
                    $result[] = new SocialAuthCandidate($reference, $channel->definition, $readiness);
                }
            }
        }
        return $result;
    }

    public function channel(SocialAuthReference $reference): RegisteredSocialAuthChannel
    {
        $this->availability->assertActive($reference->addonCode);
        $channel = $this->channels[$reference->addonCode][$reference->channelCode] ?? null;
        if ($channel === null) {
            throw new ProtocolException('reference_missing', '第三方登录渠道未注册：'.$reference->addonCode.'/'.$reference->channelCode);
        }
        if ($channel->profile($reference->profileCode)->identityNamespace !== $reference->identityNamespace) {
            throw new ProtocolException('identity_namespace_changed', '登录引用与当前应用身份域不一致。');
        }
        return $channel;
    }

    public function adapter(SocialAuthReference $reference): SocialAuthAdapterInterface
    {
        $adapter = $this->channel($reference)->resolve($this->container);
        $readiness = $adapter->readiness($reference);
        if (!$readiness->ready) {
            throw new ProtocolException('channel_not_ready', '第三方登录渠道未就绪：'.($readiness->reasonCode ?? 'unknown'));
        }
        if ($readiness->identityNamespace !== $reference->identityNamespace) {
            throw new ProtocolException('identity_namespace_changed', '实际登录应用的身份作用域已改变。');
        }
        return $adapter;
    }
}
