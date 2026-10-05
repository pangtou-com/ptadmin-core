<?php

declare(strict_types=1);

namespace PTAdmin\Payment\Contracts;

use PTAdmin\Payment\CreatePaymentRequest;
use PTAdmin\Payment\NotificationAcknowledgement;
use PTAdmin\Payment\PaymentContext;
use PTAdmin\Payment\PaymentLookup;
use PTAdmin\Payment\PaymentNotification;
use PTAdmin\Payment\PaymentNotificationRequest;
use PTAdmin\Payment\PaymentReadiness;
use PTAdmin\Payment\PaymentReference;
use PTAdmin\Payment\PaymentResult;

interface PaymentAdapterInterface
{
    public function readiness(PaymentReference $reference): PaymentReadiness;
    public function create(PaymentContext $context, CreatePaymentRequest $request): PaymentResult;
    public function query(PaymentReference $reference, PaymentLookup $payment): ?PaymentResult;
    public function parseNotify(PaymentReference $reference, PaymentNotificationRequest $request): PaymentNotification;
    public function acknowledgeNotify(PaymentReference $reference, bool $committed): NotificationAcknowledgement;
}
