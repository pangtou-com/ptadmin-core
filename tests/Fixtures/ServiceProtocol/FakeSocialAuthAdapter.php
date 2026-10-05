<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PTAdmin\Core\ServiceProtocol\Secret;
use PTAdmin\SocialAuth\{SocialAuthReference, SocialAuthReadiness, AuthorizeRequest, AuthorizeResult, CallbackRequest,
    SocialAuthTokenResult, SocialIdentity};
use PTAdmin\SocialAuth\Contracts\SocialAuthAdapterInterface;

class FakeSocialAuthAdapter implements SocialAuthAdapterInterface
{
    public SocialAuthReadiness $ready;
    public SocialIdentity $identity;
    public int $exchanges = 0;
    public ?CallbackRequest $callback = null;

    public function __construct()
    {
        $this->ready = new SocialAuthReadiness(true, 'issuer:app-original');
        $this->identity = new SocialIdentity('user-123', 'issuer:app-original', email: 'person@example.com');
    }

    public function readiness(SocialAuthReference $reference): SocialAuthReadiness
    {
        return $this->ready;
    }

    public function getAuthorizeUrl(SocialAuthReference $reference, AuthorizeRequest $request): AuthorizeResult
    {
        return new AuthorizeResult('https://provider.example/authorize?'.http_build_query([
            'redirect_uri' => $request->redirectUrl, 'state' => $request->state, 'scope' => implode(' ', $request->scopes),
            'code_challenge' => $request->pkceChallenge,
        ]));
    }

    public function handleCallback(SocialAuthReference $reference, CallbackRequest $request): SocialAuthTokenResult
    {
        ++$this->exchanges;
        $this->callback = $request;
        return new SocialAuthTokenResult(new Secret('oauth-access-secret'), 'Bearer', 'issuer:app-original',
            'user-123', new Secret('oauth-refresh-secret'));
    }

    public function getUser(SocialAuthReference $reference, SocialAuthTokenResult $tokens): SocialIdentity
    {
        return $this->identity;
    }

    public function refreshToken(SocialAuthReference $reference, Secret $refreshToken): SocialAuthTokenResult
    {
        return new SocialAuthTokenResult(new Secret('refreshed-access-secret'), 'Bearer', 'issuer:app-original');
    }
}
