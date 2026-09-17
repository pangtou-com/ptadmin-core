<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/**
 * 授权服务的完整判定结果，供布尔授权和 Schema 数据范围共同使用。
 */
final class AuthorizationDecision
{
    /** @param list<AuthorizationGrant> $grants */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly array $grants = [],
    ) {
    }
}
