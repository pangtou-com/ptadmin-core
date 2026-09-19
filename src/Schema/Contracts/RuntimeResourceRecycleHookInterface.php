<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Definition\Schema;

/**
 * 回收站生命周期的可选领域扩展点。
 *
 * 与 RuntimeResourceHookInterface 分离，避免要求现有插件 Hook 实现并不需要的回收站方法。
 */
interface RuntimeResourceRecycleHookInterface
{
    public function restoring(string $resourceKey, int $id, Schema $schema): void;

    public function restored(string $resourceKey, int $id, Schema $schema): void;

    public function purging(string $resourceKey, int $id, Schema $schema): void;

    public function purged(string $resourceKey, int $id, Schema $schema): void;
}
