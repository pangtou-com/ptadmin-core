<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\ProtocolException;
use PTAdmin\Payment\Contracts\{ClosablePaymentInterface, RefundablePaymentInterface, PreparablePaymentInterface};

/** 校验声明与规范化结果；订单事务、幂等记录、状态迁移由宿主处理。 */
final class PaymentGateway
{
    public function __construct(private PaymentRegistry $registry)
    {
    }

    public function create(PaymentContext $context, CreatePaymentRequest $request): PaymentResult
    {
        $support = $this->registry->support($context, PaymentOperation::Create);
        if (!$support->executable($context->executors)) {
            throw new ProtocolException('executor_unavailable', '宿主缺少支付流程所需的客户端执行器。');
        }
        $this->assertCurrency($request->money, $support->currencies);
        if (array_diff($request->paymentMethodTypes, $support->paymentMethodTypes) !== []) {
            throw new ProtocolException('input_invalid', '请求付款方式不在选定支持组合中。');
        }
        foreach ($support->requiredInputs as $input) {
            $value = match ($input) {
                PaymentRequiredInput::PayerReference => $request->payerReference,
                PaymentRequiredInput::ReturnUrl => $request->returnUrl,
            };
            if ($value === null) {
                throw new ProtocolException('input_invalid', '支付组合缺少必需输入：'.$input->value);
            }
        }
        $result = $this->registry->adapter($context->reference, true)->create($context, $request);
        try {
            $this->validatePayment($context->reference, $result, $context, PaymentOperation::Create);
            if ($result->payment->attemptId !== $request->attemptId || $result->money != $request->money) {
                throw new ProtocolException('result_invalid', '创建支付结果与请求号或收款金额不一致。');
            }
            if ($request->paymentMethodTypes !== [] && array_diff($result->paymentMethodTypes, $request->paymentMethodTypes) !== []) {
                throw new ProtocolException('result_invalid', '创建结果使用了请求未允许的付款方式。');
            }
        } catch (ProtocolException $exception) {
            throw $this->uncertainResult($exception);
        }
        return $result;
    }

    /** 历史查询可不传终端；需要交付交互时传入原支持组合及当前认可执行器。 */
    public function query(PaymentReference $reference, PaymentLookup $payment, ?PaymentContext $context = null): ?PaymentResult
    {
        $this->registry->assertOperation($reference, PaymentOperation::Query);
        if ($context !== null && $context->reference != $reference) {
            throw new ProtocolException('input_invalid', '查询上下文与原支付引用不一致。');
        }
        $result = $this->registry->adapter($reference)->query($reference, $payment);
        if ($result !== null) {
            $this->validatePayment($reference, $result, $context);
            $this->assertPaymentCorrelation($payment, $result->payment);
        }
        return $result;
    }

    public function close(PaymentReference $reference, PaymentLookup $payment): PaymentResult
    {
        $this->registry->assertOperation($reference, PaymentOperation::Close);
        $adapter = $this->registry->adapter($reference);
        if (!$adapter instanceof ClosablePaymentInterface) {
            throw new ProtocolException('adapter_invalid', '支付适配器未实现关单契约。');
        }
        $result = $adapter->close($reference, $payment);
        $this->validatePayment($reference, $result, operation: PaymentOperation::Close);
        $this->assertPaymentCorrelation($payment, $result->payment);
        return $result;
    }

    public function refund(PaymentReference $reference, RefundRequest $request): RefundResult
    {
        $this->registry->assertOperation($reference, PaymentOperation::Refund);
        $this->assertSceneCurrency($reference, $request->money, PaymentOperation::Refund);
        $adapter = $this->registry->adapter($reference);
        if (!$adapter instanceof RefundablePaymentInterface) {
            throw new ProtocolException('adapter_invalid', '支付适配器未实现退款契约。');
        }
        $result = $adapter->refund($reference, $request);
        try {
            $this->validateRefund($reference, $result);
            $this->assertPaymentCorrelation($request->payment, $result->refund->payment);
            if ($result->refund->refundId !== $request->refundId || $result->money != $request->money) {
                throw new ProtocolException('result_invalid', '退款结果与请求号或退款金额不一致。');
            }
        } catch (ProtocolException $exception) {
            throw $this->uncertainResult($exception);
        }
        return $result;
    }

    public function queryRefund(PaymentReference $reference, RefundLookup $refund): ?RefundResult
    {
        $this->registry->assertOperation($reference, PaymentOperation::QueryRefund);
        $adapter = $this->registry->adapter($reference);
        if (!$adapter instanceof RefundablePaymentInterface) {
            throw new ProtocolException('adapter_invalid', '支付适配器未实现退款契约。');
        }
        $result = $adapter->queryRefund($reference, $refund);
        if ($result !== null) {
            $this->validateRefund($reference, $result);
            $this->assertPaymentCorrelation($refund->payment, $result->refund->payment);
            if (($refund->refundId !== null && $refund->refundId !== $result->refund->refundId)
                || ($refund->providerRefundId !== null && $refund->providerRefundId !== $result->refund->providerRefundId)) {
                throw new ProtocolException('result_invalid', '退款查询结果与原退款定位不一致。');
            }
        }
        return $result;
    }

    public function prepare(PaymentContext $context, PreparePaymentRequest $request): PreparePaymentResult
    {
        $support = $this->registry->support($context, PaymentOperation::Prepare);
        if (!$support->executable($context->executors)) {
            throw new ProtocolException('executor_unavailable', '宿主缺少前置支付交互的执行器。');
        }
        $adapter = $this->registry->adapter($context->reference, true);
        if (!$adapter instanceof PreparablePaymentInterface) {
            throw new ProtocolException('adapter_invalid', '支付适配器未实现准备契约。');
        }
        $result = $adapter->prepare($context, $request);
        if (!$support->allows($result->interaction)) {
            throw new ProtocolException('result_invalid', '前置支付交互不在声明的支持组合中。');
        }
        return $result;
    }

    public function parseNotify(PaymentReference $reference, PaymentNotificationRequest $request): PaymentNotification
    {
        $this->registry->assertOperation($reference, PaymentOperation::ParseNotify);
        $event = $this->registry->adapter($reference)->parseNotify($reference, $request);
        if ($event->result instanceof PaymentResult) {
            $this->validatePayment($reference, $event->result, operation: PaymentOperation::ParseNotify);
        } else {
            $this->registry->assertOperation($reference, PaymentOperation::Refund);
            $this->validateRefund($reference, $event->result);
        }
        return $event;
    }

    public function acknowledgeNotify(PaymentReference $reference, bool $committed): NotificationAcknowledgement
    {
        $this->registry->assertOperation($reference, PaymentOperation::AcknowledgeNotify);
        return $this->registry->adapter($reference)->acknowledgeNotify($reference, $committed);
    }

    private function validatePayment(PaymentReference $reference, PaymentResult $result, ?PaymentContext $context = null, PaymentOperation $operation = PaymentOperation::Query): void
    {
        $this->assertMerchant($reference, $result->merchantIdentity);
        $this->assertSceneCurrency($reference, $result->money, $operation);
        $scene = $this->registry->channel($reference)->scene($reference->scene);
        if ($context !== null) {
            $supports = [$this->registry->support($context, $operation)];
        } else {
            $supports = $scene->supports;
        }
        foreach ($supports as $support) {
            if (in_array($operation, $support->operations, true)
                && in_array($result->money->currency, $support->currencies, true)
                && array_diff($result->paymentMethodTypes, $support->paymentMethodTypes) === []
                && $support->allows($result->interaction)) {
                if ($context !== null && $result->interaction->type !== PaymentInteractionType::None && !$support->executable($context->executors)) {
                    throw new ProtocolException('executor_unavailable', '宿主缺少查询结果所需的支付执行器。');
                }
                return;
            }
        }
        throw new ProtocolException('result_invalid', '支付结果的币种、付款方式与交互不属于同一支持组合。');
    }

    private function validateRefund(PaymentReference $reference, RefundResult $result): void
    {
        $this->assertMerchant($reference, $result->merchantIdentity);
        $this->assertSceneCurrency($reference, $result->money, PaymentOperation::Refund);
    }

    private function assertMerchant(PaymentReference $reference, string $identity): void
    {
        if ($identity !== $reference->merchantIdentity) {
            throw new ProtocolException('result_invalid', '支付或退款结果的商户身份与原引用不一致。');
        }
    }

    /** @param list<string> $currencies */
    private function assertCurrency(Money $money, array $currencies): void
    {
        if (!in_array($money->currency, $currencies, true)) {
            throw new ProtocolException('currency_unsupported', '支付组合不支持币种：'.$money->currency);
        }
    }

    private function assertSceneCurrency(PaymentReference $reference, Money $money, PaymentOperation $operation): void
    {
        foreach ($this->registry->channel($reference)->scene($reference->scene)->supports as $support) {
            if (in_array($operation, $support->operations, true) && in_array($money->currency, $support->currencies, true)) {
                return;
            }
        }
        throw new ProtocolException('currency_unsupported', '支付场景不支持币种：'.$money->currency);
    }

    private function assertPaymentCorrelation(PaymentLookup $expected, PaymentLookup $actual): void
    {
        if ($expected->attemptId !== null && $expected->attemptId !== $actual->attemptId) {
            throw new ProtocolException('result_invalid', '厂商结果与原支付请求号不一致。');
        }
        foreach ($expected->objects as $object) {
            if (!in_array($object, $actual->objects, false)) {
                throw new ProtocolException('result_invalid', '厂商结果丢失或改变了原支付对象引用。');
            }
        }
    }

    /** 厂商已执行写入，结果违反契约不能被解释成可以重新扣款或退款。 */
    private function uncertainResult(ProtocolException $exception): ProtocolException
    {
        return new ProtocolException($exception->errorCode, $exception->getMessage(),
            retryable: false, outcomeUncertain: true, previous: $exception);
    }

}
