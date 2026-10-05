<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

/** 仅由已验签适配器产生；业务事实去重与金额核对由宿主完成。 */
final class PaymentNotification implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly ?string $eventId,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly PaymentResult|RefundResult $result,
    ) {
        if ($eventId !== null) {
            Input::nonEmpty($eventId, 'event_id');
        }
    }
}
