<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListTreeRootsQuery 描述根节点列表查询。
 */
final class ListTreeRootsQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'filters' => [Filter::class],
        'state_kinds' => 'array',
        'state_policy' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'filters' => ['omit_empty' => true],
        'state_kinds' => ['omit_empty' => true],
        'state_policy' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Filters 过滤条件集合。 */
    public array $filters = [];

    /** StateKinds 按资源 state.values[].kind 过滤节点状态；必须配合 state_policy 使用。 */
    public array $state_kinds = [];

    /** StatePolicy 状态过滤结构策略；第一版仅支持 filter。 */
    public string $state_policy = '';
}
