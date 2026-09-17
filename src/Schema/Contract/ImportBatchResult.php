<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportBatchResult 描述资源所有者保存的幂等批次结果。
 */
final class ImportBatchResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'created_count' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** CreatedCount 本次提交成功创建的记录数量。 */
    public int $created_count = 0;
}
