<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\Input;

/** 创建与恢复客户端交互时由宿主提供真实终端和认可执行器。 */
final class PaymentContext
{
    /** @param list<PaymentExecutor> $executors */
    public function __construct(
        public readonly PaymentReference $reference,
        public readonly string $supportCode,
        public readonly PaymentTarget $target,
        public readonly array $executors = [],
    ) {
        Input::code($supportCode, 'support_code');
        Input::instances($executors, PaymentExecutor::class, 'executors', true);
    }
}
