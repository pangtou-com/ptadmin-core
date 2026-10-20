<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Data;

use PTAdmin\Captcha\Data\ChallengeData;

final class ChallengeVerifyRequest extends ChallengeData
{
    protected static function defaults(): array
    {
        return [
            'challenge_id' => '',
            'scene' => '',
            'response' => [],
            'private_state' => [],
            'client_context' => [],
            'meta' => [],
        ];
    }

    public function challengeId(): string { return (string) $this->get('challenge_id', ''); }
    public function scene(): string { return (string) $this->get('scene', ''); }
    public function response(): array { return (array) $this->get('response', []); }
    public function privateState(): array { return (array) $this->get('private_state', []); }
}
