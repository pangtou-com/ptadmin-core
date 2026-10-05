<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentOperation: string
{
    case Create = 'create';
    case Query = 'query';
    case ParseNotify = 'parse_notify';
    case AcknowledgeNotify = 'acknowledge_notify';
    case Close = 'close';
    case Refund = 'refund';
    case QueryRefund = 'query_refund';
    case Prepare = 'prepare';
}
