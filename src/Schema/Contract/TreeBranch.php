<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * TreeBranch 描述一个带子节点集合的嵌套树节点。
 * 除 Children 外，其余树元数据语义与 TreeNode 保持一致。
 */
final class TreeBranch extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'int',
        'parent_id' => 'int',
        'root_id' => 'int',
        'depth' => 'int',
        'has_children' => 'bool',
        'children_count' => 'int',
        'ancestor_ids' => 'array',
        'item' => 'array',
        'children' => [TreeBranch::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'parent_id' => ['nullable' => true, 'omit_empty' => true],
        'root_id' => ['nullable' => true, 'omit_empty' => true],
        'has_children' => ['omit_empty' => true],
        'children_count' => ['omit_empty' => true],
        'ancestor_ids' => ['omit_empty' => true],
        'item' => ['omit_empty' => true],
        'children' => ['omit_empty' => true],
    ];

    /** ID 节点主键。 */
    public int $id = 0;

    /** ParentID 父节点主键；为空时表示根节点。 */
    public ?int $parent_id = null;

    /** RootID 所属根节点主键。 */
    public ?int $root_id = null;

    /** Depth 相对查询起点的层级深度。 */
    public int $depth = 0;

    /** HasChildren 当前节点是否仍有直接子节点。 */
    public bool $has_children = false;

    /** ChildrenCount 当前节点直接子节点数量。 */
    public int $children_count = 0;

    /** AncestorIDs 从根到父节点的祖先主键路径。 */
    public array $ancestor_ids = [];

    /** Item 节点详情。 */
    public array $item = [];

    /** Children 直接子节点集合。 */
    public array $children = [];
}
