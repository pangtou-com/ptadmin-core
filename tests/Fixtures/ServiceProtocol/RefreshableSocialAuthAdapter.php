<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PTAdmin\SocialAuth\Contracts\RefreshableSocialAuthInterface;

final class RefreshableSocialAuthAdapter extends FakeSocialAuthAdapter implements RefreshableSocialAuthInterface
{
}
