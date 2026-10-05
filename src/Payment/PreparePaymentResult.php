<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class PreparePaymentResult implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly bool $ready,
        public readonly ?string $payerReference,
        public readonly PaymentInteraction $interaction,
    ) {
        if ($payerReference !== null) {
            Input::nonEmpty($payerReference, 'payer_reference');
        }
        if (!$ready && $interaction->type === PaymentInteractionType::None) {
            throw new ProtocolException('result_invalid', '未完成付款人准备时必须提供前置交互。');
        }
    }
}
