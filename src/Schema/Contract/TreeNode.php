<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * TreeNode 描述树结构中的一个节点。
 * parent_id 树关系字段；root_id、has_children、children_count、ancestor_ids 都是只读派生字段。
 */
final class TreeNode extends ContractObject
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
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'parent_id' => ['nullable' => true, 'omit_empty' => true],
        'root_id' => ['nullable' => true, 'omit_empty' => true],
        'has_children' => ['omit_empty' => true],
        'children_count' => ['omit_empty' => true],
        'ancestor_ids' => ['omit_empty' => true],
        'item' => ['omit_empty' => true],
    ];

    /** ID 节点主键。 */
    public int $id = 0;

    /** ParentID 父节点主键；为空时表示根节点。 */
    public ?int $parent_id = null;

    /** RootID 所属根节点主键，查询阶段回填的只读派生字段。 */
    public ?int $root_id = null;

    /** Depth 相对查询起点的层级深度。 */
    public int $depth = 0;

    /** HasChildren 当前节点是否仍有直接子节点，查询阶段回填的只读派生字段。 */
    public bool $has_children = false;

    /** ChildrenCount 当前节点直接子节点数量，查询阶段回填的只读派生字段。 */
    public int $children_count = 0;

    /** AncestorIDs 从根到父节点的祖先主键路径，查询阶段回填的只读派生字段。 */
    public array $ancestor_ids = [];

    /** Item 节点详情。 */
    public array $item = [];
}
