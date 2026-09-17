<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * MoveTreeNodeResult 描述移动节点结果。
 */
final class MoveTreeNodeResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'node' => TreeNode::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Node 移动后的节点详情。 */
    public ?TreeNode $node = null;
}
