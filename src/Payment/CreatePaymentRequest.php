<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\Input;

final class CreatePaymentRequest
{
    /** @param list<string> $paymentMethodTypes @param array<string,mixed> $options */
    public function __construct(
        public readonly string $attemptId,
        public readonly string $idempotencyKey,
        public readonly Money $money,
        public readonly string $subject,
        public readonly string $notifyUrl,
        public readonly ?string $returnUrl = null,
        public readonly ?string $payerReference = null,
        public readonly array $paymentMethodTypes = [],
        public readonly array $options = [],
    ) {
        Input::nonEmpty($attemptId, 'attempt_id');
        Input::nonEmpty($idempotencyKey, 'idempotency_key');
        Input::nonEmpty($subject, 'subject');
        Input::url($notifyUrl, 'notify_url');
        if ($returnUrl !== null) {
            Input::url($returnUrl, 'return_url');
        }
        if ($payerReference !== null) {
            Input::nonEmpty($payerReference, 'payer_reference');
        }
        Input::codes($paymentMethodTypes, 'payment_method_types');
    }
}
