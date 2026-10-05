<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException, PublicDto};

/** 实际商户身份应来自当前生效配置，不能照抄调用引用。 */
final class PaymentReadiness implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly bool $ready,
        public readonly ?string $merchantIdentity,
        public readonly ?string $reasonCode = null,
        public readonly bool $acceptingNewPayments = true,
    ) {
        if ($ready) {
            Input::nonEmpty($merchantIdentity ?? '', 'merchant_identity');
        } elseif ($reasonCode === null) {
            throw new ProtocolException('result_invalid', '未就绪支付渠道必须提供安全原因编码。');
        }
        if ($reasonCode !== null) {
            Input::code($reasonCode, 'reason_code');
        }
    }
}
