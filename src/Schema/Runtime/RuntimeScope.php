<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Contracts\RuntimeEngineInterface;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的 Runtime 调用作用域。
 *
 * Laravel 业务代码只需传入资源键字符串，作用域会在内部转换为协议 `ResourceKey`，并在每次调用时覆盖载荷中的
 * resource 字段。这样可以避免查询参数意外切换到其他资源，同时保持底层 RuntimeEngine 无状态。
 */
final class RuntimeScope extends ResourceBoundScope
{
    public function __construct(string $resource, private RuntimeEngineInterface $runtime)
    {
        parent::__construct($resource);
    }

    /** 分页查询当前资源记录。 */
    public function list(array|object $query = []): array
    {
        return $this->runtime->list($this->resourcePayload($query));
    }

    /** 查询当前资源全部记录，max 用于限制结果数量。 */
    public function getAll(array|object $query = [], ?int $max = null): array
    {
        return $this->runtime->getAll($this->resourcePayload($query), $max);
    }

    /** 返回当前资源符合条件的第一条记录。 */
    public function first(array|object $query = []): array
    {
        return $this->runtime->first($this->resourcePayload($query));
    }

    /** 按详情查询参数读取当前资源的一条记录。 */
    public function detail(array|object $query): array
    {
        return $this->runtime->detail($this->resourcePayload($query));
    }

    /** 按主键读取当前资源的一条记录。 */
    public function find(int $id): array
    {
        return $this->runtime->find($this->resourceKey(), $id);
    }

    /** 在当前资源中创建记录。 */
    public function create(array|object $input): array
    {
        return $this->runtime->create($this->resourcePayload($input));
    }

    /** 更新当前资源中的记录。 */
    public function update(array|object $input): array
    {
        return $this->runtime->update($this->resourcePayload($input));
    }

    /** 按资源回收站能力删除当前资源中的记录。 */
    public function delete(array|object $input): array
    {
        return $this->runtime->delete($this->resourcePayload($input));
    }

    /** 恢复当前资源回收站中的记录。 */
    public function restore(array|object $input): array
    {
        return $this->runtime->restore($this->resourcePayload($input));
    }

    /** 永久删除当前资源中的记录，该操作不可恢复。 */
    public function purge(array|object $input): array
    {
        return $this->runtime->purge($this->resourcePayload($input));
    }

    /** 执行当前资源声明的内置或宿主动作。 */
    public function executeAction(array|object $input): array
    {
        return $this->runtime->executeAction($this->resourcePayload($input));
    }

    /** 查询当前资源关系字段的候选项。 */
    public function relationCandidates(array|object $query): array
    {
        return $this->runtime->relationCandidates($this->resourcePayload($query));
    }

    /** 返回当前资源的活动 Schema 快照。 */
    public function schemaSnapshot(array|object $query = []): array
    {
        return $this->runtime->schemaSnapshot($this->resourcePayload($query));
    }
}
