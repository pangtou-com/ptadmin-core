<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ExecuteActionInput 描述执行资源动作输入。
 */
final class ExecuteActionInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'action' => 'string',
        'id' => 'int',
        'ids' => 'array',
        'data' => 'array',
        'return_detail' => 'bool',
        'meta' => 'array',
        'scene' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'id' => ['omit_empty' => true],
        'ids' => ['omit_empty' => true],
        'data' => ['omit_empty' => true],
        'return_detail' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Action 动作键。 */
    public string $action = '';

    /** ID 单记录动作的主键值。 */
    public int $id = 0;

    /** IDs 批量动作的主键值集合。 */
    public array $ids = [];

    /** Data 动作标准入参载荷。 */
    public array $data = [];

    /** ReturnDetail 单记录动作是否回填详情结果。 */
    public bool $return_detail = false;

    /** Meta 动作运行辅助参数。 */
    public array $meta = [];

    /** Scene 调用场景标识。 */
    public string $scene = '';
}
