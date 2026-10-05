<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth\Contracts;

use PTAdmin\SocialAuth\SocialAuthRegistration;

interface SocialAuthProviderInterface
{
    public function register(SocialAuthRegistration $registration): void;
}
