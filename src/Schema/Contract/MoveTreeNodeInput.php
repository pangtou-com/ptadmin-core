<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * MoveTreeNodeInput 描述树节点移动输入。
 *
 * 约定：
 * - 只传 ParentID 表示换父节点，不承诺同级位置。
 * - 只传 BeforeID / AfterID 表示按目标兄弟节点定位。
 * - 同时传 ParentID 与 BeforeID / AfterID 时，ParentID 必须与目标兄弟节点父级一致。
 * - BeforeID 和 AfterID 不能同时传。
 */
final class MoveTreeNodeInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'id' => 'int',
        'parent_id' => 'int',
        'before_id' => 'int',
        'after_id' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'parent_id' => ['nullable' => true, 'omit_empty' => true],
        'before_id' => ['nullable' => true, 'omit_empty' => true],
        'after_id' => ['nullable' => true, 'omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ID 要移动的节点主键。 */
    public int $id = 0;

    /** ParentID 为空时表示移动为根节点；与 BeforeID / AfterID 组合使用时表示目标父节点约束。 */
    public ?int $parent_id = null;

    /** BeforeID 移动到指定同级节点之前。 */
    public ?int $before_id = null;

    /** AfterID 移动到指定同级节点之后。 */
    public ?int $after_id = null;
}
