<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

/** 微信 unionid 等附加身份也必须保留实际身份作用域。 */
final class ScopedIdentity implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly string $type,
        public readonly string $identityNamespace,
        public readonly string $subject,
    ) {
        Input::code($type, 'identity_type');
        Input::nonEmpty($identityNamespace, 'identity_namespace');
        Input::nonEmpty($subject, 'subject');
    }
}
