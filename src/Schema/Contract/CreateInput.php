<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CreateInput 描述创建记录输入。
 */
final class CreateInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'data' => 'array',
        'mode' => 'string',
        'scene' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'mode' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Data 待创建记录数据。 */
    public array $data = [];

    /** Mode 写入模式，默认为 submit；draft 会跳过必填完整性校验。 */
    public string $mode = '';

    /** Scene 调用场景标识。 */
    public string $scene = '';
}
