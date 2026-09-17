<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/**
 * 资源生命周期、草稿编辑和版本查询服务边界。
 */
interface ResourceServiceInterface
{
    /** 创建资源及其初始草稿。 */
    public function createResource(array|object $input): mixed;
    /** 分页列出资源元数据。 */
    public function listResources(array|object $query = []): mixed;
    /** 查询资源当前状态和能力。 */
    public function getResource(string $resourceKey): mixed;
    /** 获取资源草稿 Schema。 */
    public function getDraftSchema(string $resourceKey): mixed;
    /** 开始一次草稿编辑并返回编辑上下文。 */
    public function beginDraftEdit(string $resourceKey): mixed;
    /** 保存草稿 Schema，校验失败时不改变原草稿。 */
    public function saveDraftSchema(array|object $input): mixed;
    /** 获取活动版本快照。 */
    public function getActiveVersion(string $resourceKey): mixed;
    /** 分页查询资源历史版本。 */
    public function listVersions(array|object $query): mixed;
    /** 查询指定版本详情。 */
    public function getVersion(array|object $query): mixed;
    /** 同步代码来源资源，保留版本审计信息。 */
    public function syncCodeResource(array|object $input): mixed;
    /** 删除尚未发布且没有产生迁移副作用的 Studio 初始草稿资源。 */
    public function deleteDraftResource(string $resourceKey): void;
    /** 禁用资源运行时访问。 */
    public function disableResource(string $resourceKey): void;
    /** 启用资源运行时访问。 */
    public function enableResource(string $resourceKey): void;
    /** 归档资源；归档后不应继续创建新业务记录。 */
    public function archiveResource(string $resourceKey): void;
}
