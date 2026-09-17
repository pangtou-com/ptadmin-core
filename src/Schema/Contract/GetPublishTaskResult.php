<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetPublishTaskResult 描述发布任务查询结果。
 */
final class GetPublishTaskResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'task' => PublishTaskInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Task 命中的发布任务信息。 */
    public ?PublishTaskInfo $task = null;
}
