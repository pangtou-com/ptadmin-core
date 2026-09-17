<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetTreeAncestorsResult 描述祖先链查询结果。
 */
final class GetTreeAncestorsResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'items' => [TreeNode::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Items 从根到当前节点的路径集合。 */
    public array $items = [];
}
