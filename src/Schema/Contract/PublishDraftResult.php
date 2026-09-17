<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * PublishDraftResult 描述发布草稿后的结果。
 */
final class PublishDraftResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'task' => PublishTaskInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Task 新创建或推进后的发布任务信息。 */
    public ?PublishTaskInfo $task = null;
}
