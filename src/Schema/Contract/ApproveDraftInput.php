<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ApproveDraftInput 描述一次绑定当前检查快照的破坏性发布批准。
 */
final class ApproveDraftInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'expected_checksum' => 'string',
        'approved_by' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'expected_checksum' => ['omit_empty' => true],
        'approved_by' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ExpectedChecksum 调用方内部绑定的检查快照校验值，不要求终端用户手工输入。 */
    public string $expected_checksum = '';

    /** ApprovedBy 执行批准的可信主体标识。 */
    public string $approved_by = '';
}
