<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class PaymentLookup implements \JsonSerializable
{
    use PublicDto;

    /** @param list<ProviderObjectReference> $objects */
    public function __construct(public readonly ?string $attemptId = null, public readonly array $objects = [])
    {
        if ($attemptId !== null) {
            Input::nonEmpty($attemptId, 'attempt_id');
        }
        Input::instances($objects, ProviderObjectReference::class, 'objects', true);
        if ($attemptId === null && $objects === []) {
            throw new ProtocolException('input_invalid', '支付定位必须包含原请求号或厂商对象引用。');
        }
    }
}
