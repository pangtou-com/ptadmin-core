<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Core\ServiceProtocol\{AddonAvailability, Input, ProtocolException};
use PTAdmin\Payment\Contracts\PaymentAdapterInterface;

final class PaymentRegistry
{
    /** @var array<string,array<string,RegisteredPaymentChannel>> */
    private array $channels = [];

    public function __construct(private Container $container, private AddonAvailability $availability)
    {
    }

    /** @internal 只能由插件加载器提交已验证的插件归属。 */
    public function assertCanCommit(string $addonCode): void
    {
        Input::code($addonCode, 'addon_code');
        if (isset($this->channels[$addonCode])) {
            throw new ProtocolException('definition_conflict', '插件支付声明已经注册：'.$addonCode);
        }
    }

    /** @internal @param array<string,RegisteredPaymentChannel> $channels */
    public function commit(string $addonCode, array $channels): void
    {
        $this->assertCanCommit($addonCode);
        $this->channels[$addonCode] = $channels;
    }

    /** @return array<string,array<string,PaymentDefinition>> */
    public function definitions(): array
    {
        $result = [];
        foreach ($this->channels as $owner => $channels) {
            $result[$owner] = array_map(static fn (RegisteredPaymentChannel $channel): PaymentDefinition => $channel->definition, $channels);
        }
        return $result;
    }

    /** @return list<PaymentCandidate> */
    public function discover(PaymentRequirements $requirements): array
    {
        $result = [];
        foreach ($this->channels as $owner => $channels) {
            if (($requirements->addonCode !== null && $requirements->addonCode !== $owner) || !$this->availability->active($owner)) {
                continue;
            }
            foreach ($channels as $code => $channel) {
                if ($requirements->channelCode !== null && $requirements->channelCode !== $code) {
                    continue;
                }
                foreach ($channel->definition->scenes as $scene) {
                    if ($requirements->scenes !== [] && !in_array($scene->code, $requirements->scenes, true)) {
                        continue;
                    }
                    foreach ($channel->profiles as $profile) {
                        if ($requirements->profileCode !== null && $requirements->profileCode !== $profile->code) {
                            continue;
                        }
                        $reference = new PaymentReference($owner, $code, $profile->code, $scene->code, $profile->merchantIdentity);
                        $readiness = null;
                        foreach ($scene->supports as $support) {
                            if (!$requirements->matches($support)) {
                                continue;
                            }
                            $readiness ??= $channel->resolve($this->container)->readiness($reference);
                            $ready = $readiness->ready && $readiness->merchantIdentity === $reference->merchantIdentity;
                            $operations = $requirements->operations ?: [PaymentOperation::Create];
                            $newPayment = in_array(PaymentOperation::Create, $operations, true) || in_array(PaymentOperation::Prepare, $operations, true);
                            if (!$ready) {
                                $reason = $readiness->ready ? 'merchant_changed' : $readiness->reasonCode;
                            } elseif ($newPayment && !$readiness->acceptingNewPayments) {
                                $ready = false;
                                $reason = 'new_payments_disabled';
                            } else {
                                $reason = null;
                            }
                            if ($requirements->availableOnly && !$ready) {
                                continue;
                            }
                            $result[] = new PaymentCandidate(
                                $reference, $channel->definition->title, $scene->title, $support,
                                new PaymentReadiness($ready, $readiness->merchantIdentity, $reason, $readiness->acceptingNewPayments),
                                in_array(PaymentRequiredInput::PayerReference, $support->requiredInputs, true),
                            );
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function channel(PaymentReference $reference): RegisteredPaymentChannel
    {
        $this->availability->assertActive($reference->addonCode);
        $channel = $this->channels[$reference->addonCode][$reference->channelCode] ?? null;
        if ($channel === null) {
            throw new ProtocolException('reference_missing', '支付渠道未注册：'.$reference->addonCode.'/'.$reference->channelCode);
        }
        $channel->scene($reference->scene);
        if ($channel->profile($reference->profileCode)->merchantIdentity !== $reference->merchantIdentity) {
            throw new ProtocolException('merchant_changed', '支付历史引用与当前配置档案的商户身份不一致。');
        }
        return $channel;
    }

    public function adapter(PaymentReference $reference, bool $newPayment = false): PaymentAdapterInterface
    {
        $adapter = $this->channel($reference)->resolve($this->container);
        $readiness = $adapter->readiness($reference);
        if (!$readiness->ready) {
            throw new ProtocolException('channel_not_ready', '支付渠道未就绪：'.($readiness->reasonCode ?? 'unknown'));
        }
        if ($readiness->merchantIdentity !== $reference->merchantIdentity) {
            throw new ProtocolException('merchant_changed', '实际商户配置已改变，拒绝调用历史支付。');
        }
        if ($newPayment && !$readiness->acceptingNewPayments) {
            throw new ProtocolException('new_payments_disabled', '渠道已停止接受新支付。');
        }
        return $adapter;
    }

    public function support(PaymentContext $context, PaymentOperation $operation): PaymentSupport
    {
        foreach ($this->channel($context->reference)->scene($context->reference->scene)->supports as $support) {
            if ($support->code === $context->supportCode && $support->target === $context->target) {
                if (!in_array($operation, $support->operations, true)) {
                    throw new ProtocolException('operation_unsupported', '支付支持组合不支持操作：'.$operation->value);
                }
                return $support;
            }
        }
        throw new ProtocolException('support_unsupported', '支付终端或支持组合不匹配：'.$context->supportCode);
    }

    public function assertOperation(PaymentReference $reference, PaymentOperation $operation): void
    {
        foreach ($this->channel($reference)->scene($reference->scene)->supports as $support) {
            if (in_array($operation, $support->operations, true)) {
                return;
            }
        }
        throw new ProtocolException('operation_unsupported', '支付场景不支持操作：'.$operation->value);
    }
}
