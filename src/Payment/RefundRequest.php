<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};

final class RefundRequest
{
    /** @param array<string,mixed> $options */
    public function __construct(
        public readonly PaymentLookup $payment,
        public readonly string $refundId,
        public readonly string $idempotencyKey,
        public readonly Money $money,
        public readonly Money $originalMoney,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $reason = null,
        public readonly array $options = [],
    ) {
        Input::nonEmpty($refundId, 'refund_id');
        Input::nonEmpty($idempotencyKey, 'idempotency_key');
        if ($money->currency !== $originalMoney->currency || $money->amountMinor > $originalMoney->amountMinor) {
            throw new ProtocolException('input_invalid', '退款币种必须与原支付相同，退款金额不能超过原支付金额。');
        }
        if ($notifyUrl !== null) {
            Input::url($notifyUrl, 'notify_url');
        }
    }
}
