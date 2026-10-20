<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Data;

use InvalidArgumentException;
use PTAdmin\Captcha\ChallengeStatus;
use PTAdmin\Captcha\Data\ChallengeData;

final class ChallengeVerifyResult extends ChallengeData
{
    protected static function defaults(): array
    {
        return [
            'status' => ChallengeStatus::REJECTED,
            'reason_code' => null,
            'retry_after' => null,
            'meta' => [],
            'raw' => null,
        ];
    }

    protected static function normalize(array $data): array
    {
        $attributes = parent::normalize($data);
        if (!in_array($attributes['status'], ChallengeStatus::all(), true)) {
            throw new InvalidArgumentException('Captcha challenge status is invalid.');
        }

        return $attributes;
    }

    public function status(): string { return (string) $this->get('status', ChallengeStatus::REJECTED); }
    public function passed(): bool { return ChallengeStatus::PASSED === $this->status(); }
}
