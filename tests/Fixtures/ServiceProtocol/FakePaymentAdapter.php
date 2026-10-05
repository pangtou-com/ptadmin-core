<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Fixtures\ServiceProtocol;

use PTAdmin\Core\ServiceProtocol\ProtocolException;
use PTAdmin\Payment\{PaymentReference, PaymentReadiness, PaymentContext, CreatePaymentRequest, PaymentResult,
    PaymentLookup, PaymentStatus, Money, PaymentInteraction, PaymentNotificationRequest, PaymentNotification,
    NotificationAcknowledgement, RefundRequest, RefundResult, RefundLookup, RefundStatus, PreparePaymentRequest, PreparePaymentResult};
use PTAdmin\Payment\Contracts\PaymentAdapterInterface;

class FakePaymentAdapter implements PaymentAdapterInterface
{
    public PaymentReadiness $ready;
    public PaymentInteraction $interaction;
    public PaymentStatus $status = PaymentStatus::Pending;
    public ?PaymentResult $queryResult = null;
    public ?ProtocolException $failure = null;
    public int $writes = 0;
    public int $queries = 0;
    public int $readinessCalls = 0;
    /** @var list<CreatePaymentRequest|RefundRequest> */
    public array $requests = [];
    public ?RefundResult $refundResult = null;
    public ?PaymentResult $notificationResult = null;

    public function __construct()
    {
        $this->ready = new PaymentReadiness(true, 'merchant-original');
        $this->interaction = PaymentInteraction::none();
    }

    public function readiness(PaymentReference $reference): PaymentReadiness
    {
        ++$this->readinessCalls;
        return $this->ready;
    }

    public function create(PaymentContext $context, CreatePaymentRequest $request): PaymentResult
    {
        ++$this->writes;
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return new PaymentResult(new PaymentLookup($request->attemptId), $this->status, $request->money,
            $this->ready->merchantIdentity ?? '', $this->interaction);
    }

    public function query(PaymentReference $reference, PaymentLookup $payment): ?PaymentResult
    {
        ++$this->queries;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->queryResult;
    }

    public function parseNotify(PaymentReference $reference, PaymentNotificationRequest $request): PaymentNotification
    {
        $signature = $request->headers()['X-Test-Signature'][0] ?? '';
        if (!hash_equals(hash_hmac('sha256', $request->body(), 'fixture-key'), $signature)) {
            throw new ProtocolException('notification_verification_failed', '测试通知签名无效。');
        }
        if ($this->notificationResult === null) {
            throw new \LogicException('未设置测试通知结果。');
        }
        return new PaymentNotification('evt_fixture', new \DateTimeImmutable('2026-09-30T00:00:00Z'), $this->notificationResult);
    }

    public function acknowledgeNotify(PaymentReference $reference, bool $committed): NotificationAcknowledgement
    {
        return new NotificationAcknowledgement($committed ? 200 : 500, $committed ? 'success' : 'retry', ['Content-Type' => 'text/plain']);
    }

    public function close(PaymentReference $reference, PaymentLookup $payment): PaymentResult
    {
        return $this->queryResult ?? new PaymentResult($payment, PaymentStatus::Closed, new Money(100, 'CNY'),
            $reference->merchantIdentity, PaymentInteraction::none());
    }

    public function refund(PaymentReference $reference, RefundRequest $request): RefundResult
    {
        ++$this->writes;
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->refundResult ?? new RefundResult(new RefundLookup($request->payment, $request->refundId, 're_fixture'),
            RefundStatus::Succeeded, $request->money, $reference->merchantIdentity, new \DateTimeImmutable('2026-09-30T01:00:00Z'));
    }

    public function queryRefund(PaymentReference $reference, RefundLookup $refund): ?RefundResult
    {
        ++$this->queries;
        return $this->refundResult;
    }

    public function prepare(PaymentContext $context, PreparePaymentRequest $request): PreparePaymentResult
    {
        return new PreparePaymentResult(true, 'payer-fixture', PaymentInteraction::none());
    }
}
