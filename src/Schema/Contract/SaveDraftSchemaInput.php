<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SaveDraftSchemaInput 描述保存草稿 schema 的输入。
 */
final class SaveDraftSchemaInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'schema' => \PTAdmin\Schema\Definition\Schema::class,
        'expected_checksum' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'expected_checksum' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Schema 待保存的草稿 schema。 */
    public ?\PTAdmin\Schema\Definition\Schema $schema = null;

    /** ExpectedChecksum 调用方期望匹配的草稿校验值。 */
    public string $expected_checksum = '';
}
