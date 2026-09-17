<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Service;

use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的元数据、草稿、版本和状态调用作用域。
 */
final class ResourceScope extends ResourceBoundScope
{
    public function __construct(string $resource, private ResourceServiceInterface $resources)
    {
        parent::__construct($resource);
    }

    /** 查询当前资源信息。 */
    public function getResource(): mixed
    {
        return $this->resources->getResource($this->resourceKey());
    }

    /** 获取当前资源的草稿 Schema。 */
    public function getDraftSchema(): mixed
    {
        return $this->resources->getDraftSchema($this->resourceKey());
    }

    /** 开始当前资源的草稿编辑。 */
    public function beginDraftEdit(): mixed
    {
        return $this->resources->beginDraftEdit($this->resourceKey());
    }

    /** 保存当前资源草稿，input 只需携带 schema 和 expected_checksum。 */
    public function saveDraftSchema(array|object $input): mixed
    {
        return $this->resources->saveDraftSchema($this->resourcePayload($input));
    }

    /** 获取当前资源活动版本。 */
    public function getActiveVersion(): mixed
    {
        return $this->resources->getActiveVersion($this->resourceKey());
    }

    /** 分页查询当前资源历史版本。 */
    public function listVersions(array|object $query = []): mixed
    {
        return $this->resources->listVersions($this->resourcePayload($query));
    }

    /** 查询当前资源指定版本。 */
    public function getVersion(array|object $query): mixed
    {
        return $this->resources->getVersion($this->resourcePayload($query));
    }

    /** 将代码来源 Schema 同步到当前资源。 */
    public function syncCodeResource(array|object $input): mixed
    {
        return $this->resources->syncCodeResource($this->resourcePayload($input));
    }

    /** 删除当前资源的初始 Studio 草稿；发布过或产生迁移副作用时会拒绝删除。 */
    public function deleteDraftResource(): void
    {
        $this->resources->deleteDraftResource($this->resourceKey());
    }

    /** 禁用当前资源运行时访问。 */
    public function disableResource(): void
    {
        $this->resources->disableResource($this->resourceKey());
    }

    /** 启用当前资源运行时访问。 */
    public function enableResource(): void
    {
        $this->resources->enableResource($this->resourceKey());
    }

    /** 归档当前资源，归档后不能重新启用。 */
    public function archiveResource(): void
    {
        $this->resources->archiveResource($this->resourceKey());
    }
}
