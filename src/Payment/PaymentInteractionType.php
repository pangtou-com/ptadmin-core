<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentInteractionType: string
{
    case QrCode = 'qr_code';
    case Redirect = 'redirect';
    case FormSubmit = 'form_submit';
    case ClientInvoke = 'client_invoke';
    case None = 'none';
}
