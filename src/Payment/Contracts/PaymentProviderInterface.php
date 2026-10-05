<?php

declare(strict_types=1);

namespace PTAdmin\Payment\Contracts;

use PTAdmin\Payment\PaymentRegistration;

interface PaymentProviderInterface
{
    public function register(PaymentRegistration $registration): void;
}
