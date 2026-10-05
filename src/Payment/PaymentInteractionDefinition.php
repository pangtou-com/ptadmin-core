<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{ProtocolException, PublicDto};

final class PaymentInteractionDefinition implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly PaymentInteractionType $type,
        public readonly ?PaymentExecutor $executor = null,
    ) {
        if (($type === PaymentInteractionType::ClientInvoke) !== ($executor !== null)) {
            throw new ProtocolException('definition_invalid', '客户端拉起必须且只能声明一个执行器。');
        }
    }

    /** @param list<PaymentExecutor> $executors */
    public function executable(array $executors): bool
    {
        if ($this->executor === null) {
            return true;
        }

        foreach ($executors as $executor) {
            if ($this->executor->matches($executor)) {
                return true;
            }
        }

        return false;
    }
}
