<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\Input;

final class SocialAuthRequirements
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly array $scopes = [],
        public readonly ?string $addonCode = null,
        public readonly ?string $channelCode = null,
        public readonly ?string $profileCode = null,
        public readonly bool $requireRefresh = false,
        public readonly bool $availableOnly = true,
    ) {
        Input::scopes($scopes);
        foreach (['addonCode', 'channelCode', 'profileCode'] as $field) {
            if ($this->$field !== null) {
                Input::code($this->$field, $field);
            }
        }
    }
}
