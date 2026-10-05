<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\PublicDto;

final class PaymentCandidate implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly PaymentReference $reference,
        public readonly string $channelTitle,
        public readonly string $sceneTitle,
        public readonly PaymentSupport $support,
        public readonly PaymentReadiness $readiness,
        public readonly bool $preparationRequired,
    ) {
    }
}
