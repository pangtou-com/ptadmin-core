<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PTAdmin\Payment\Contracts\{ClosablePaymentInterface, RefundablePaymentInterface, PreparablePaymentInterface};

final class FullPaymentAdapter extends FakePaymentAdapter implements ClosablePaymentInterface, RefundablePaymentInterface, PreparablePaymentInterface
{
}
