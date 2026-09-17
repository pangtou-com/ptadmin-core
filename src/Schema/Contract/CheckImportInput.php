<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CheckImportInput 描述导入检查输入。
 */
final class CheckImportInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'source_ref' => 'string',
        'mode' => 'string',
        'mapping' => [ImportColumnMapping::class],
        'scene' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'mode' => ['omit_empty' => true],
        'mapping' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
    ];

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** SourceRef 宿主文件存储返回的来源引用，Schema 包不直接读取本地路径。 */
    public string $source_ref = '';

    /** Mode 当前导入或写入执行模式。 */
    public string $mode = '';

    /** Mapping 导入列与 Schema 字段之间的映射。 */
    public array $mapping = [];

    /** Scene 宿主传入的调用场景标识。 */
    public string $scene = '';
}
