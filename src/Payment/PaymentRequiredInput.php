<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentRequiredInput: string
{
    case PayerReference = 'payer_reference';
    case ReturnUrl = 'return_url';
}
