<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto, Secret};

/** 服务端令牌只允许适配器显式读取，公共序列化只能看到脱敏标记。 */
final class SocialAuthTokenResult implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly Secret $accessToken,
        public readonly string $tokenType,
        public readonly string $identityNamespace,
        public readonly ?string $subject = null,
        public readonly ?Secret $refreshToken = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
        Input::nonEmpty($tokenType, 'token_type');
        Input::nonEmpty($identityNamespace, 'identity_namespace');
        if ($subject !== null) {
            Input::nonEmpty($subject, 'subject');
        }
    }
}
