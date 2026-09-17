<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth\Session;

final class SessionToken
{
    public function __construct(
        private string $accessToken,
        private string $sessionId,
        private string $expiresAt,
        private string $absoluteExpiresAt
    ) {
    }

    /**
     * @return array{access_token:string, session_id:string, expires_at:string, absolute_expires_at:string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'session_id' => $this->sessionId,
            'expires_at' => $this->expiresAt,
            'absolute_expires_at' => $this->absoluteExpiresAt,
        ];
    }
}
