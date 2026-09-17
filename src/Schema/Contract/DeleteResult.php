<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * DeleteResult 描述删除记录结果。
 */
final class DeleteResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'success' => 'bool',
        'affected' => 'int',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'affected' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
    ];

    /** Success 删除是否成功。 */
    public bool $success = false;

    /** Affected 受影响记录数。 */
    public int $affected = 0;

    /** Meta 附加元信息。 */
    public array $meta = [];
}
