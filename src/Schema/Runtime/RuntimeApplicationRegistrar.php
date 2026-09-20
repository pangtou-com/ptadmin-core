<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Closure;

/** 宿主应用的 Runtime 注册入口，Owner 固定为 app/host。 */
final class RuntimeApplicationRegistrar
{
    public function __construct(private RuntimeRegistry $registry)
    {
    }

    /** @param Closure(RuntimeContributor):void $callback */
    public function register(Closure $callback): void
    {
        $callback($this->registry->forOwner('app', 'host'));
    }
}
