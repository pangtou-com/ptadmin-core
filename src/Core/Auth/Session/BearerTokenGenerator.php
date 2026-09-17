<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth\Session;

final class BearerTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
