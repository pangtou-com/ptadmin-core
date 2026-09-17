<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use PTAdmin\Schema\Contract\ResourceKey;

/**
 * 资源作用域公共基类，统一负责字符串资源键到协议 `ResourceKey` 的转换和载荷注入。
 */
abstract class ResourceBoundScope
{
    private ResourceKey $resource;

    protected function __construct(string $resource)
    {
        $this->resource = ResourceKey::resource($resource);
    }

    /** 返回底层协议服务使用的资源键字符串。 */
    final protected function resourceKey(): string
    {
        return $this->resource->toString();
    }

    /**
     * 注入当前资源键，并覆盖外部载荷中的同名字段，防止链式上下文被调用参数切换。
     *
     * @return array<string, mixed>
     */
    final protected function resourcePayload(array|object $payload, string $field = 'resource'): array
    {
        $values = Payload::array($payload);
        $values[$field] = $this->resourceKey();

        return $values;
    }
}
