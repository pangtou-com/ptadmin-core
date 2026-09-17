<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * UpdateInput 描述更新记录输入。
 */
final class UpdateInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'id' => 'int',
        'data' => 'array',
        'mode' => 'string',
        'scene' => 'string',
        'server_constraint' => ServerConstraint::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'mode' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
        'server_constraint' => ['nullable' => true, 'serialized' => false],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ID 目标记录主键值。 */
    public int $id = 0;

    /** Data 待更新字段集合。 */
    public array $data = [];

    /** Mode 写入模式，默认为 submit；draft 会跳过必填完整性校验。 */
    public string $mode = '';

    /** Scene 调用场景标识。 */
    public string $scene = '';

    /** ServerConstraint 是宿主可信组件附加的访问约束，不接受外部 JSON 输入。 */
    public ?ServerConstraint $server_constraint = null;
}
