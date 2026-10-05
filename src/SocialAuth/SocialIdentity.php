<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class SocialIdentity implements \JsonSerializable
{
    use PublicDto;

    /** @param list<ScopedIdentity> $additionalIdentities */
    public function __construct(
        public readonly string $subject,
        public readonly string $identityNamespace,
        public readonly ?string $nickname = null,
        public readonly ?string $avatar = null,
        public readonly ?string $email = null,
        public readonly bool $emailVerified = false,
        public readonly ?string $mobile = null,
        public readonly bool $mobileVerified = false,
        public readonly array $additionalIdentities = [],
    ) {
        Input::nonEmpty($subject, 'subject');
        Input::nonEmpty($identityNamespace, 'identity_namespace');
        Input::instances($additionalIdentities, ScopedIdentity::class, 'additional_identities', true);
        if (($emailVerified && $email === null) || ($mobileVerified && $mobile === null)) {
            throw new ProtocolException('result_invalid', '验证事实不能缺少对应邮箱或手机号。');
        }
        if ($avatar !== null) {
            Input::url($avatar, 'avatar');
        }
    }
}
