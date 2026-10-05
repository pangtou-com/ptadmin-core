<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\Input;

final class PaymentRequirements
{
    /**
     * @param list<PaymentOperation> $operations
     * @param list<PaymentScene> $scenes
     * @param list<PaymentInteractionType> $interactions
     * @param list<PaymentExecutor> $executors
     * @param list<string> $paymentMethodTypes
     */
    public function __construct(
        public readonly PaymentTarget $target,
        public readonly ?string $currency = null,
        public readonly array $operations = [PaymentOperation::Create],
        public readonly array $scenes = [],
        public readonly array $interactions = [],
        public readonly array $executors = [],
        public readonly array $paymentMethodTypes = [],
        public readonly ?string $addonCode = null,
        public readonly ?string $channelCode = null,
        public readonly ?string $profileCode = null,
        public readonly bool $availableOnly = true,
    ) {
        if ($currency !== null) {
            Input::currency($currency);
        }
        Input::instances($operations, PaymentOperation::class, 'operations', true);
        Input::instances($scenes, PaymentScene::class, 'scenes', true);
        Input::instances($interactions, PaymentInteractionType::class, 'interactions', true);
        Input::instances($executors, PaymentExecutor::class, 'executors', true);
        Input::codes($paymentMethodTypes, 'payment_method_types');
        foreach (['addonCode', 'channelCode', 'profileCode'] as $field) {
            if ($this->$field !== null) {
                Input::code($this->$field, $field);
            }
        }
    }

    public function matches(PaymentSupport $support): bool
    {
        foreach ($this->operations ?: [PaymentOperation::Create] as $operation) {
            if (!in_array($operation, $support->operations, true)) {
                return false;
            }
        }
        return $support->target === $this->target
            && ($this->currency === null || in_array($this->currency, $support->currencies, true))
            && ($this->interactions === [] || in_array($support->interaction->type, $this->interactions, true))
            && ($this->paymentMethodTypes === [] || array_intersect($this->paymentMethodTypes, $support->paymentMethodTypes) !== [])
            && $support->executable($this->executors);
    }
}
