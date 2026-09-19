<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/**
 * 一次授权判定的可信服务端上下文，仅允许服务端提供附加判定属性。
 */
final class AuthorizationContext
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly array $attributes = [],
    ) {
    }
}
