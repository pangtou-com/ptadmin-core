<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SchemaSnapshotQuery 描述运行时 schema 快照查询输入。
 */
final class SchemaSnapshotQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'scene' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'scene' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Scene 调用场景标识。 */
    public string $scene = '';
}
