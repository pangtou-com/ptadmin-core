<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Data;

use PTAdmin\Captcha\Data\ChallengeData;

final class ChallengeCreateResult extends ChallengeData
{
    protected static function defaults(): array
    {
        return [
            'challenge_id' => '',
            'type' => '',
            'expires_at' => null,
            'presentation' => [],
            'response_schema' => [],
            'renderer' => [],
            'private_state' => [],
            'meta' => [],
        ];
    }

    public function challengeId(): string { return (string) $this->get('challenge_id', ''); }
    public function type(): string { return (string) $this->get('type', ''); }
    public function privateState(): array { return (array) $this->get('private_state', []); }

    /** @return array<string, mixed> */
    public function publicData(): array
    {
        $data = $this->attributes;
        unset($data['private_state']);

        return $data;
    }

    /** 默认序列化只公开挑战，服务端使用 privateState() 读取答案。 */
    public function toArray(): array
    {
        return $this->publicData();
    }
}
