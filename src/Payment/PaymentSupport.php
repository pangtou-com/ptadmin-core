<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

/** 一条组合完整描述实际流程，避免从多个组合拼出不存在的支付方式。 */
final class PaymentSupport implements \JsonSerializable
{
    use PublicDto;

    /**
     * @param list<PaymentOperation> $operations
     * @param list<string> $currencies
     * @param list<string> $paymentMethodTypes
     * @param list<PaymentRequiredInput> $requiredInputs
     * @param list<PaymentInteractionDefinition> $followUpInteractions
     */
    public function __construct(
        public readonly string $code,
        public readonly PaymentTarget $target,
        public readonly PaymentInteractionDefinition $interaction,
        public readonly array $operations,
        public readonly array $currencies,
        public readonly array $paymentMethodTypes = [],
        public readonly array $requiredInputs = [],
        public readonly array $followUpInteractions = [],
    ) {
        Input::code($code, 'support_code');
        Input::instances($operations, PaymentOperation::class, 'operations');
        Input::codes($currencies, 'currencies', false);
        foreach ($currencies as $currency) {
            Input::currency($currency);
        }
        Input::codes($paymentMethodTypes, 'payment_method_types');
        Input::instances($requiredInputs, PaymentRequiredInput::class, 'required_inputs', true);
        Input::instances($followUpInteractions, PaymentInteractionDefinition::class, 'follow_up_interactions', true);
    }

    /** @param list<PaymentExecutor> $executors */
    public function executable(array $executors): bool
    {
        foreach ([$this->interaction, ...$this->followUpInteractions] as $interaction) {
            if (!$interaction->executable($executors)) {
                return false;
            }
        }
        return true;
    }

    public function allows(PaymentInteraction $interaction): bool
    {
        if ($interaction->type === PaymentInteractionType::None) {
            return true;
        }
        foreach ([$this->interaction, ...$this->followUpInteractions] as $definition) {
            if ($definition->type === $interaction->type && $definition->executor == $interaction->executor) {
                return true;
            }
        }
        return false;
    }
}
