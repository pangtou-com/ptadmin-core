<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ApproveDraftResult 描述已经持久化的破坏性发布批准。
 */
final class ApproveDraftResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'task' => PublishTaskInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Task 待执行发布任务；其检查快照变化后批准自动失效。 */
    public ?PublishTaskInfo $task = null;
}
