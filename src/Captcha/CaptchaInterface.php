<?php

declare(strict_types=1);

namespace PTAdmin\Captcha;

use PTAdmin\Captcha\Protocol\CaptchaDefinition;

use PTAdmin\Captcha\Data\ChallengeCreateRequest;
use PTAdmin\Captcha\Data\ChallengeCreateResult;
use PTAdmin\Captcha\Data\ChallengeRefreshRequest;
use PTAdmin\Captcha\Data\ChallengeVerifyRequest;
use PTAdmin\Captcha\Data\ChallengeVerifyResult;

interface CaptchaInterface
{
    public function definition(): CaptchaDefinition;

    public function create(ChallengeCreateRequest $request): ChallengeCreateResult;

    public function verify(ChallengeVerifyRequest $request): ChallengeVerifyResult;

    public function refresh(ChallengeRefreshRequest $request): ChallengeCreateResult;
}
