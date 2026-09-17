<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetTreeResult 描述嵌套树查询结果。
 */
final class GetTreeResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'items' => [TreeBranch::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Items 命中的树分支集合。 */
    public array $items = [];
}
