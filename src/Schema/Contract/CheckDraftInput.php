<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CheckDraftInput 描述草稿检查输入。
 */
final class CheckDraftInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'expected_checksum' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'expected_checksum' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** ExpectedChecksum 调用方期望匹配的草稿校验值。 */
    public string $expected_checksum = '';
}
