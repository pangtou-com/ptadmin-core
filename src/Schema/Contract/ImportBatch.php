<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportBatch 描述交给资源所有者原子提交的一批已准备记录。
 */
final class ImportBatch extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'session_id' => 'string',
        'resource' => ResourceKey::class,
        'rows' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** SessionID 导入会话的稳定标识。 */
    public string $session_id = '';

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** Rows 当前批次或预览包含的数据行。 */
    public array $rows = [];
}
