<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class SocialAuthReference implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly string $addonCode,
        public readonly string $channelCode,
        public readonly string $profileCode,
        public readonly string $identityNamespace,
        public readonly int $protocolVersion = 1,
    ) {
        foreach (['addonCode', 'channelCode', 'profileCode'] as $field) {
            Input::code($this->$field, $field);
        }
        Input::nonEmpty($identityNamespace, 'identity_namespace');
        if ($protocolVersion !== 1) {
            throw new ProtocolException('protocol_unsupported', '第三方登录协议版本不受支持。');
        }
    }
}
