<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * PublishTaskRef 描述发布任务的稳定引用。
 */
final class PublishTaskRef extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'task_id' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** TaskID 发布任务唯一标识。 */
    public int $task_id = 0;
}
