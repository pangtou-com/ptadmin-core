<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * DetailQuery 描述详情查询输入。
 */
final class DetailQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'id' => 'int',
        'with' => 'array',
        'scene' => 'string',
        'server_constraint' => ServerConstraint::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'with' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
        'server_constraint' => ['nullable' => true, 'serialized' => false],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ID 目标记录主键值。 */
    public int $id = 0;

    /** With 需展开的关联字段列表。 */
    public array $with = [];

    /** Scene 调用场景标识。 */
    public string $scene = '';

    /** ServerConstraint 是宿主可信组件附加的访问约束，不接受外部 JSON 输入。 */
    public ?ServerConstraint $server_constraint = null;
}
