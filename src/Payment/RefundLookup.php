<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class RefundLookup implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly PaymentLookup $payment,
        public readonly ?string $refundId = null,
        public readonly ?string $providerRefundId = null,
    ) {
        if ($refundId === null && $providerRefundId === null) {
            throw new ProtocolException('input_invalid', '退款定位必须包含原退款请求号或厂商退款号。');
        }
        foreach (['refundId', 'providerRefundId'] as $field) {
            if ($this->$field !== null) {
                Input::nonEmpty($this->$field, $field);
            }
        }
    }
}
