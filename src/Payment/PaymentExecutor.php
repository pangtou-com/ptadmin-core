<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

final class PaymentExecutor implements \JsonSerializable
{
    use PublicDto;

    public function __construct(public readonly string $code, public readonly string $version)
    {
        Input::code($code, 'executor_code');
        Input::code($version, 'executor_version');
    }

    public function matches(self $other): bool
    {
        return $this->code === $other->code && $this->version === $other->version;
    }
}
