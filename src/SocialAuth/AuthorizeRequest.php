<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};

final class AuthorizeRequest
{
    /** @param list<string> $scopes @param array<string,mixed> $options */
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $state,
        public readonly array $scopes = [],
        public readonly ?string $pkceChallenge = null,
        public readonly array $options = [],
    ) {
        Input::url($redirectUrl, 'redirect_url');
        Input::nonEmpty($state, 'state');
        Input::scopes($scopes);
        if ($pkceChallenge !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $pkceChallenge) !== 1) {
            throw new ProtocolException('input_invalid', 'PKCE challenge 必须是 S256 的 base64url 摘要。');
        }
    }
}
