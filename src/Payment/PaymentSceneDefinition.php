<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class PaymentSceneDefinition implements \JsonSerializable
{
    use PublicDto;

    /** @param list<PaymentSupport> $supports */
    public function __construct(
        public readonly PaymentScene $code,
        public readonly string $title,
        public readonly array $supports,
    ) {
        Input::nonEmpty($title, 'scene_title');
        Input::instances($supports, PaymentSupport::class, 'supports');
        $codes = array_map(static fn (PaymentSupport $support): string => $support->code, $supports);
        if (count($codes) !== count(array_unique($codes))) {
            throw new ProtocolException('definition_conflict', '同一支付场景不能重复声明支持组合编码。');
        }
    }
}
