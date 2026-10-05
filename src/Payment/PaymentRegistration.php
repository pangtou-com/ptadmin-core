<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\ProtocolException;
use PTAdmin\Payment\Contracts\PaymentAdapterInterface;

/** 插件加载器分配归属，Provider 只提交本插件的渠道。 */
final class PaymentRegistration
{
    /** @var array<string,RegisteredPaymentChannel> */
    private array $channels = [];

    /** @param PaymentAdapterInterface|string $adapter @param list<PaymentProfile> $profiles */
    public function add(PaymentDefinition $definition, PaymentAdapterInterface|string $adapter, array $profiles): void
    {
        if (isset($this->channels[$definition->code])) {
            throw new ProtocolException('definition_conflict', '同一插件重复声明支付渠道：'.$definition->code);
        }
        $this->channels[$definition->code] = new RegisteredPaymentChannel($definition, $adapter, $profiles);
    }

    /** @return array<string,RegisteredPaymentChannel> */
    public function channels(): array
    {
        return $this->channels;
    }
}
