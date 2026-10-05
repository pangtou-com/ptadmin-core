<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

final class AuthorizeResult implements \JsonSerializable
{
    use PublicDto;

    public function __construct(public readonly string $url, public readonly ?\DateTimeImmutable $expiresAt = null)
    {
        Input::url($url, 'authorize_url');
    }
}
