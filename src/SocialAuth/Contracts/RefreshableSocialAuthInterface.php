<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth\Contracts;

use PTAdmin\SocialAuth\SocialAuthReference;
use PTAdmin\SocialAuth\SocialAuthTokenResult;
use PTAdmin\Core\ServiceProtocol\Secret;

interface RefreshableSocialAuthInterface
{
    public function refreshToken(SocialAuthReference $reference, Secret $refreshToken): SocialAuthTokenResult;
}
