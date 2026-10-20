<?php

declare(strict_types=1);

namespace PTAdmin\Captcha;

final class ChallengeStatus
{
    public const PASSED = 'passed';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const PAYLOAD_INVALID = 'payload_invalid';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::PASSED,
            self::REJECTED,
            self::EXPIRED,
            self::LOCKED,
            self::PROVIDER_UNAVAILABLE,
            self::PAYLOAD_INVALID,
        ];
    }
}
