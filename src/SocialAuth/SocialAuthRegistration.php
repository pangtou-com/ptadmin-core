<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\ProtocolException;
use PTAdmin\SocialAuth\Contracts\SocialAuthAdapterInterface;

final class SocialAuthRegistration
{
    /** @var array<string,RegisteredSocialAuthChannel> */
    private array $channels = [];

    /** @param SocialAuthAdapterInterface|string $adapter @param list<SocialAuthProfile> $profiles */
    public function add(SocialAuthDefinition $definition, SocialAuthAdapterInterface|string $adapter, array $profiles): void
    {
        if (isset($this->channels[$definition->code])) {
            throw new ProtocolException('definition_conflict', '同一插件重复声明登录渠道：'.$definition->code);
        }
        $this->channels[$definition->code] = new RegisteredSocialAuthChannel($definition, $adapter, $profiles);
    }

    /** @return array<string,RegisteredSocialAuthChannel> */
    public function channels(): array
    {
        return $this->channels;
    }
}
