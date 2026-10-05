<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

final class PaymentDefinition implements \JsonSerializable
{
    use PublicDto;

    /** @param list<PaymentSceneDefinition> $scenes */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $scenes,
        public readonly ?int $idempotencyRetentionSeconds = null,
    ) {
        Input::code($code, 'channel_code');
        Input::nonEmpty($title, 'channel_title');
        Input::instances($scenes, PaymentSceneDefinition::class, 'scenes');
        $codes = array_map(static fn (PaymentSceneDefinition $scene): string => $scene->code->value, $scenes);
        if (count($codes) !== count(array_unique($codes))) {
            throw new ProtocolException('definition_conflict', '同一支付渠道不能重复声明场景。');
        }
        if ($idempotencyRetentionSeconds !== null && $idempotencyRetentionSeconds <= 0) {
            throw new ProtocolException('definition_invalid', '厂商幂等保留期限必须为正整数秒或未知 null。');
        }
    }
}
