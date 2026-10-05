<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

final class SocialAuthDefinition implements \JsonSerializable
{
    use PublicDto;

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $scopes = [],
        public readonly bool $supportsRefresh = false,
        public readonly bool $requiresPkce = false,
    ) {
        Input::code($code, 'channel_code');
        Input::nonEmpty($title, 'channel_title');
        Input::scopes($scopes);
    }
}
