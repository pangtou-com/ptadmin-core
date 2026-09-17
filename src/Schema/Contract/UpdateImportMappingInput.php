<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * UpdateImportMappingInput 描述导入映射调整输入。
 */
final class UpdateImportMappingInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'session_id' => 'string',
        'revision' => 'int',
        'mapping' => [ImportColumnMapping::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** SessionID 导入会话的稳定标识。 */
    public string $session_id = '';

    /** Revision 会话修订号，提交时用于阻止旧映射覆盖新状态。 */
    public int $revision = 0;

    /** Mapping 导入列与 Schema 字段之间的映射。 */
    public array $mapping = [];
}
