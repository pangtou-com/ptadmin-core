<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth\Exceptions;

use Illuminate\Auth\AuthenticationException;

/** 后台认证错误保留会话失效与账号不可用的响应编码。 */
final class AdminAuthenticationException extends AuthenticationException
{
    public function __construct(string $message, public readonly int $errorCode = 40101)
    {
        parent::__construct($message, ['ptadmin']);
    }
}
