<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use LogicException;
use PTAdmin\Schema\Contracts\RuntimeResourceHookInterface;

/**
 * 保存由宿主 PHP 代码显式注册的资源 Hook；不从 Schema JSON 或请求载荷解析可执行类。
 */
final class RuntimeResourceHookRegistry
{
    /** @var array<string, RuntimeResourceHookInterface> */
    private array $hooks = [];

    public function register(RuntimeResourceHookInterface $hook): void
    {
        foreach ($hook->resources() as $resourceKey) {
            if ($resourceKey === '') {
                throw new LogicException('Runtime resource hook key cannot be empty.');
            }
            if (isset($this->hooks[$resourceKey]) && $this->hooks[$resourceKey] !== $hook) {
                throw new LogicException('Runtime resource hook is already registered: '.$resourceKey);
            }
            $this->hooks[$resourceKey] = $hook;
        }
    }

    public function get(string $resourceKey): ?RuntimeResourceHookInterface
    {
        return $this->hooks[$resourceKey] ?? null;
    }
}
