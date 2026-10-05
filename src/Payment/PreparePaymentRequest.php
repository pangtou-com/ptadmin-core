<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\Input;

final class PreparePaymentRequest
{
    /** @param array<string,mixed> $options */
    public function __construct(public readonly string $returnUrl, public readonly array $options = [])
    {
        Input::url($returnUrl, 'return_url');
    }
}
