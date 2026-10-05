<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, Secret, ProtocolException};

/** 宿主必须先核对并消费一次性 state；本对象不代替认证上下文验证。 */
final class CallbackRequest
{
    /** @param array<string,mixed> $options */
    public function __construct(
        public readonly Secret $code,
        public readonly string $redirectUrl,
        public readonly ?Secret $pkceVerifier = null,
        public readonly array $options = [],
    ) {
        Input::url($redirectUrl, 'redirect_url');
        if ($pkceVerifier !== null && preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $pkceVerifier->reveal()) !== 1) {
            throw new ProtocolException('input_invalid', 'PKCE verifier 格式无效。');
        }
    }
}
