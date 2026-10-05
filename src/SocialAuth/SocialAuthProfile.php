<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\Input;

final class SocialAuthProfile
{
    public function __construct(public readonly string $code, public readonly string $identityNamespace)
    {
        Input::code($code, 'profile_code');
        Input::nonEmpty($identityNamespace, 'identity_namespace');
    }
}
