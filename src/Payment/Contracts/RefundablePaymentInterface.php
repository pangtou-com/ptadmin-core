<?php

declare(strict_types=1);

namespace PTAdmin\Payment\Contracts;

use PTAdmin\Payment\PaymentReference;
use PTAdmin\Payment\RefundLookup;
use PTAdmin\Payment\RefundRequest;
use PTAdmin\Payment\RefundResult;

interface RefundablePaymentInterface
{
    public function refund(PaymentReference $reference, RefundRequest $request): RefundResult;
    public function queryRefund(PaymentReference $reference, RefundLookup $refund): ?RefundResult;
}
