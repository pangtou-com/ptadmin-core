<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

/** 随支付尝试保存；商户身份固定，密钥轮换不改变商户身份。 */
final class PaymentReference implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly string $addonCode,
        public readonly string $channelCode,
        public readonly string $profileCode,
        public readonly PaymentScene $scene,
        public readonly string $merchantIdentity,
        public readonly int $protocolVersion = 1,
    ) {
        foreach (['addonCode', 'channelCode', 'profileCode'] as $field) {
            Input::code($this->$field, $field);
        }
        Input::nonEmpty($merchantIdentity, 'merchant_identity');
        if ($protocolVersion !== 1) {
            throw new ProtocolException('protocol_unsupported', '支付协议版本不受支持。');
        }
    }
}
