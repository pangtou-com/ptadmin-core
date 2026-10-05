<?php

declare(strict_types=1);

namespace PTAdmin\Payment\Contracts;

use PTAdmin\Payment\PaymentLookup;
use PTAdmin\Payment\PaymentReference;
use PTAdmin\Payment\PaymentResult;

interface ClosablePaymentInterface
{
    public function close(PaymentReference $reference, PaymentLookup $payment): PaymentResult;
}
