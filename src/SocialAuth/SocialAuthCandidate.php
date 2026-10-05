<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\PublicDto;

final class SocialAuthCandidate implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly SocialAuthReference $reference,
        public readonly SocialAuthDefinition $definition,
        public readonly SocialAuthReadiness $readiness,
    ) {
    }
}
