<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Closed = 'closed';
    case Expired = 'expired';
}
