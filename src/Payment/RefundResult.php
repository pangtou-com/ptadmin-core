<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class RefundResult implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly RefundLookup $refund,
        public readonly RefundStatus $status,
        public readonly Money $money,
        public readonly string $merchantIdentity,
        public readonly ?\DateTimeImmutable $refundedAt = null,
    ) {
        Input::nonEmpty($merchantIdentity, 'merchant_identity');
        if ($refundedAt !== null && $status !== RefundStatus::Succeeded) {
            throw new ProtocolException('result_invalid', '只有退款成功才能提供退款完成时间。');
        }
    }
}
