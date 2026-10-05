<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};
use PTAdmin\Payment\Contracts\{PaymentAdapterInterface, ClosablePaymentInterface, RefundablePaymentInterface, PreparablePaymentInterface};

/** 只保存本地声明；注册时不创建 SDK 或读取商户凭据。 */
final class RegisteredPaymentChannel
{
    /** @param PaymentAdapterInterface|string $adapter @param list<PaymentProfile> $profiles */
    public function __construct(
        public readonly PaymentDefinition $definition,
        private readonly PaymentAdapterInterface|string $adapter,
        public readonly array $profiles,
    ) {
        Input::instances($profiles, PaymentProfile::class, 'profiles');
        $codes = array_map(static fn (PaymentProfile $profile): string => $profile->code, $profiles);
        if (count($codes) !== count(array_unique($codes))) {
            throw new ProtocolException('definition_conflict', '支付配置档案编码重复：'.$definition->code);
        }
        if (!is_a($adapter, PaymentAdapterInterface::class, true)) {
            throw new ProtocolException('adapter_invalid', '支付适配器必须实现 PaymentAdapterInterface：'.$definition->code);
        }
        foreach ($definition->scenes as $scene) {
            foreach ($scene->supports as $support) {
                foreach ([PaymentOperation::Create, PaymentOperation::Query, PaymentOperation::ParseNotify, PaymentOperation::AcknowledgeNotify] as $operation) {
                    if (!in_array($operation, $support->operations, true)) {
                        throw new ProtocolException('definition_invalid', '支付支持组合必须声明创建、查询与通知操作：'.$support->code);
                    }
                }
                foreach ($support->operations as $operation) {
                    $interface = match ($operation) {
                        PaymentOperation::Close => ClosablePaymentInterface::class,
                        PaymentOperation::Refund, PaymentOperation::QueryRefund => RefundablePaymentInterface::class,
                        PaymentOperation::Prepare => PreparablePaymentInterface::class,
                        default => null,
                    };
                    if ($interface !== null && !is_a($adapter, $interface, true)) {
                        throw new ProtocolException('adapter_invalid', '支付声明与适配器操作不一致：'.$definition->code.'/'.$operation->value);
                    }
                }
                if (in_array(PaymentOperation::Refund, $support->operations, true) !== in_array(PaymentOperation::QueryRefund, $support->operations, true)) {
                    throw new ProtocolException('definition_invalid', '退款与退款查询必须成组声明：'.$support->code);
                }
            }
        }
    }

    public function resolve(Container $container): PaymentAdapterInterface
    {
        $adapter = is_string($this->adapter) ? $container->make($this->adapter) : $this->adapter;
        if (!$adapter instanceof PaymentAdapterInterface) {
            throw new ProtocolException('adapter_invalid', '支付容器解析结果不符合契约：'.$this->definition->code);
        }
        return $adapter;
    }

    public function scene(PaymentScene $code): PaymentSceneDefinition
    {
        foreach ($this->definition->scenes as $scene) {
            if ($scene->code === $code) {
                return $scene;
            }
        }
        throw new ProtocolException('reference_missing', '支付场景未注册：'.$this->definition->code.'/'.$code->value);
    }

    public function profile(string $code): PaymentProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->code === $code) {
                return $profile;
            }
        }
        throw new ProtocolException('reference_missing', '支付配置档案未注册：'.$this->definition->code.'/'.$code);
    }
}
