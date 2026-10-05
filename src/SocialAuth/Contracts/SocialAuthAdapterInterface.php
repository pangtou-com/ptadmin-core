<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth\Contracts;

use PTAdmin\SocialAuth\AuthorizeRequest;
use PTAdmin\SocialAuth\AuthorizeResult;
use PTAdmin\SocialAuth\CallbackRequest;
use PTAdmin\SocialAuth\SocialAuthReadiness;
use PTAdmin\SocialAuth\SocialAuthReference;
use PTAdmin\SocialAuth\SocialAuthTokenResult;
use PTAdmin\SocialAuth\SocialIdentity;

interface SocialAuthAdapterInterface
{
    public function readiness(SocialAuthReference $reference): SocialAuthReadiness;
    public function getAuthorizeUrl(SocialAuthReference $reference, AuthorizeRequest $request): AuthorizeResult;
    public function handleCallback(SocialAuthReference $reference, CallbackRequest $request): SocialAuthTokenResult;
    public function getUser(SocialAuthReference $reference, SocialAuthTokenResult $tokens): SocialIdentity;
}
