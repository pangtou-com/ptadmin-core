<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class SocialAuthReadiness implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly bool $ready,
        public readonly ?string $identityNamespace,
        public readonly ?string $reasonCode = null,
    ) {
        if ($ready) {
            Input::nonEmpty($identityNamespace ?? '', 'identity_namespace');
        } elseif ($reasonCode === null) {
            throw new ProtocolException('result_invalid', '未就绪登录渠道必须提供原因编码。');
        }
        if ($reasonCode !== null) {
            Input::code($reasonCode, 'reason_code');
        }
    }
}
