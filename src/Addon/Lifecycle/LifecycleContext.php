<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

/** 生命周期回调接收的稳定插件上下文。 */
final class LifecycleContext
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public readonly string $code,
        public readonly string $directory,
        public readonly array $manifest,
    ) {
    }
}
