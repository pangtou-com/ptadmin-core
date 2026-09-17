<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListTreeResult 描述树节点列表结果。
 */
final class ListTreeResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'items' => [TreeNode::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Items 命中的节点集合。 */
    public array $items = [];
}
