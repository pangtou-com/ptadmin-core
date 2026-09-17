<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/**
 * 草稿校验、审批、迁移发布和投影重建的服务边界。
 */
interface PublishServiceInterface
{
    /** 校验草稿并生成迁移预览。 */
    public function checkDraft(array|object $input): mixed;
    /** 审批通过草稿，使其具备发布资格。 */
    public function approveDraft(array|object $input): mixed;
    /** 查询当前草稿审批状态。 */
    public function getDraftApproval(string $resourceKey): mixed;
    /** 执行 Schema 迁移并切换活动版本。 */
    public function publishDraft(array|object $input): mixed;
    /** 查询发布任务及阶段结果。 */
    public function getPublishTask(int $taskId): mixed;
    /** 根据活动版本重建字段投影。 */
    public function rebuildFieldsProjection(string $resourceKey): mixed;
}
