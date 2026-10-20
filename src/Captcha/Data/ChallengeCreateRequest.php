<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Data;

use PTAdmin\Captcha\Data\ChallengeData;

final class ChallengeCreateRequest extends ChallengeData
{
    protected static function defaults(): array
    {
        return [
            'scene' => '',
            'locale' => null,
            'client_context' => [],
            'meta' => [],
        ];
    }

    public function scene(): string { return (string) $this->get('scene', ''); }
    public function locale(): ?string { return null === $this->get('locale') ? null : (string) $this->get('locale'); }
    public function clientContext(): array { return (array) $this->get('client_context', []); }
}
