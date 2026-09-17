<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * PurgeInput 描述彻底清除记录输入。
 */
final class PurgeInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'id' => 'int',
        'server_constraint' => ServerConstraint::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'server_constraint' => ['nullable' => true, 'serialized' => false],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ID 目标记录主键值。 */
    public int $id = 0;

    /** ServerConstraint 是宿主可信组件附加的访问约束，不接受外部 JSON 输入。 */
    public ?ServerConstraint $server_constraint = null;
}
