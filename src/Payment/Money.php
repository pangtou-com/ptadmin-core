<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class Money implements \JsonSerializable
{
    use PublicDto;

    public function __construct(public readonly int $amountMinor, public readonly string $currency)
    {
        if ($amountMinor <= 0) {
            throw new ProtocolException('input_invalid', '支付或退款金额必须是正整数最小货币单位。');
        }
        Input::currency($currency);
    }
}
