<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Closed = 'closed';
}
