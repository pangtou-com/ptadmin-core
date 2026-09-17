<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListQuery 查询结构。
 */
final class ListQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'filters' => [Filter::class],
        'state_kinds' => 'array',
        'sorts' => [Sort::class],
        'page' => 'int',
        'limit' => 'int',
        'scene' => 'string',
        'recycle_mode' => 'string',
        'server_constraint' => ServerConstraint::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'filters' => ['omit_empty' => true],
        'state_kinds' => ['omit_empty' => true],
        'sorts' => ['omit_empty' => true],
        'page' => ['omit_empty' => true],
        'limit' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
        'recycle_mode' => ['omit_empty' => true],
        'server_constraint' => ['nullable' => true, 'serialized' => false],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Filters 过滤条件集合。 */
    public array $filters = [];

    /** StateKinds 按资源 state.values[].kind 过滤业务状态；为空时不做状态过滤。 */
    public array $state_kinds = [];

    /** Sorts 排序条件集合。 */
    public array $sorts = [];

    /** Page 页码，从 1 开始。 */
    public int $page = 0;

    /** Limit 每页条数。 */
    public int $limit = 0;

    /** Scene 调用场景标识。 */
    public string $scene = '';

    /** RecycleMode 回收站查询模式，空值表示只查正常记录，only 表示只查回收站，with 表示包含回收站。 */
    public string $recycle_mode = '';

    /** ServerConstraint 是宿主可信组件附加的访问约束，不接受外部 JSON 输入。 */
    public ?ServerConstraint $server_constraint = null;
}
