<?php

declare(strict_types=1);

namespace PTAdmin\User\Events;

/** 用户停用、删除或失去宿主规定的有效资格；各插件处理自己的业务联动。 */
final readonly class UserUnavailable
{
    public function __construct(public int $userId)
    {
    }
}
