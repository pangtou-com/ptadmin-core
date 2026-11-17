<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth\Session;

/** 通知请求内身份清理已撤销会话；不携带原始 Token。 */
final class SessionRevoked
{
    public function __construct(
        public readonly ?string $tokenHash = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $guardName = null,
    ) {
    }
}
