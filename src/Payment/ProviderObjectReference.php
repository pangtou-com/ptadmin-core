<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, PublicDto};

/** 厂商对象类型及用途，例如 checkout_session / checkout、payment_intent / payment。 */
final class ProviderObjectReference implements \JsonSerializable
{
    use PublicDto;

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $purpose,
    ) {
        Input::code($type, 'object_type');
        Input::nonEmpty($id, 'object_id');
        Input::code($purpose, 'object_purpose');
    }
}
