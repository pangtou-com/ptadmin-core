<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Publish\Service;

use PTAdmin\Schema\Contracts\PublishServiceInterface;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的草稿检查、审批和发布作用域。
 */
final class PublishScope extends ResourceBoundScope
{
    public function __construct(string $resource, private PublishServiceInterface $publish)
    {
        parent::__construct($resource);
    }

    /** 校验当前资源草稿并生成迁移计划。 */
    public function checkDraft(array|object $input = []): mixed
    {
        return $this->publish->checkDraft($this->resourcePayload($input));
    }

    /** 批准当前资源已经检查过的破坏性迁移。 */
    public function approveDraft(array|object $input = []): mixed
    {
        return $this->publish->approveDraft($this->resourcePayload($input));
    }

    /** 查询当前资源草稿审批状态。 */
    public function getDraftApproval(): mixed
    {
        return $this->publish->getDraftApproval($this->resourceKey());
    }

    /** 发布当前资源草稿并切换活动版本。 */
    public function publishDraft(array|object $input = []): mixed
    {
        return $this->publish->publishDraft($this->resourcePayload($input));
    }

    /** 根据当前资源活动版本重建字段投影。 */
    public function rebuildFieldsProjection(): mixed
    {
        return $this->publish->rebuildFieldsProjection($this->resourceKey());
    }
}
