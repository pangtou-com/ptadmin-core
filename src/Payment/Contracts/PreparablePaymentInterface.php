<?php

declare(strict_types=1);

namespace PTAdmin\Payment\Contracts;

use PTAdmin\Payment\PaymentContext;
use PTAdmin\Payment\PreparePaymentRequest;
use PTAdmin\Payment\PreparePaymentResult;

interface PreparablePaymentInterface
{
    public function prepare(PaymentContext $context, PreparePaymentRequest $request): PreparePaymentResult;
}
