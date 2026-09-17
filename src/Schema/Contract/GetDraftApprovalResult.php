<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetDraftApprovalResult 描述当前草稿的一次性发布批准状态。
 */
final class GetDraftApprovalResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'approved' => 'bool',
        'task' => PublishTaskInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'task' => ['omit_empty' => true],
    ];

    /** Approved 表示待执行任务与当前草稿检查快照仍然匹配。 */
    public bool $approved = false;

    /** Task 已批准的待执行任务。 */
    public ?PublishTaskInfo $task = null;
}
