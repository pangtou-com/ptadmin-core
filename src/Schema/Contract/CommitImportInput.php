<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CommitImportInput 描述导入提交输入。
 */
final class CommitImportInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'session_id' => 'string',
        'revision' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** SessionID 导入会话的稳定标识。 */
    public string $session_id = '';

    /** Revision 会话修订号，提交时用于阻止旧映射覆盖新状态。 */
    public int $revision = 0;
}
