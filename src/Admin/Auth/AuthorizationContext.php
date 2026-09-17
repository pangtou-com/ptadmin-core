<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/**
 * 一次授权判定的可信服务端上下文。上下文为空时表示判断主体是否在任意合法范围内拥有能力。
 */
final class AuthorizationContext
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly ?int $tenantId = null,
        public readonly ?int $organizationId = null,
        public readonly ?int $departmentId = null,
        public readonly array $attributes = [],
    ) {
    }
}
