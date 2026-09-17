<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetTreeSubtreeQuery 描述子树查询。
 * 返回结果为平铺节点列表，层级信息通过 Depth 表达。
 */
final class GetTreeSubtreeQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'root_id' => 'int',
        'include_root' => 'bool',
        'filters' => [Filter::class],
        'state_kinds' => 'array',
        'state_policy' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'include_root' => ['omit_empty' => true],
        'filters' => ['omit_empty' => true],
        'state_kinds' => ['omit_empty' => true],
        'state_policy' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** RootID 子树根节点主键。 */
    public int $root_id = 0;

    /** IncludeRoot 结果中是否包含根节点自身。 */
    public bool $include_root = false;

    /** Filters 过滤条件集合。 */
    public array $filters = [];

    /** StateKinds 按资源 state.values[].kind 过滤节点状态；必须配合 state_policy 使用。 */
    public array $state_kinds = [];

    /** StatePolicy 状态过滤结构策略；第一版仅支持 filter。 */
    public string $state_policy = '';
}
