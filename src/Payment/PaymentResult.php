<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class PaymentResult implements \JsonSerializable
{
    use PublicDto;

    /** @param list<string> $paymentMethodTypes */
    public function __construct(
        public readonly PaymentLookup $payment,
        public readonly PaymentStatus $status,
        public readonly Money $money,
        public readonly string $merchantIdentity,
        public readonly PaymentInteraction $interaction,
        public readonly ?\DateTimeImmutable $paidAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly array $paymentMethodTypes = [],
    ) {
        Input::nonEmpty($merchantIdentity, 'merchant_identity');
        Input::codes($paymentMethodTypes, 'payment_method_types');
        if ($status === PaymentStatus::RequiresAction && $interaction->type === PaymentInteractionType::None) {
            throw new ProtocolException('result_invalid', '需要付款人操作的支付结果必须提供交互。');
        }
        if (in_array($status, [PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Closed, PaymentStatus::Expired], true)
            && $interaction->type !== PaymentInteractionType::None) {
            throw new ProtocolException('result_invalid', '支付终态不能继续要求付款人执行交互。');
        }
        if ($paidAt !== null && $status !== PaymentStatus::Succeeded) {
            throw new ProtocolException('result_invalid', '只有已收款结果才能提供支付完成时间。');
        }
    }
}
