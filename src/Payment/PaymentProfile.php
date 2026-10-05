<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\Input;

final class PaymentProfile
{
    public function __construct(public readonly string $code, public readonly string $merchantIdentity)
    {
        Input::code($code, 'profile_code');
        Input::nonEmpty($merchantIdentity, 'merchant_identity');
    }
}
