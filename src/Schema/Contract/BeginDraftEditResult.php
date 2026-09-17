<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * BeginDraftEditResult 描述开始编辑草稿后的结果。
 */
final class BeginDraftEditResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'schema' => \PTAdmin\Schema\Definition\Schema::class,
        'created' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 当前可编辑草稿版本信息。 */
    public ?VersionInfo $version = null;

    /** Schema 当前可编辑草稿 schema。 */
    public ?\PTAdmin\Schema\Definition\Schema $schema = null;

    /** Created 本次是否新建了草稿版本。 */
    public bool $created = false;
}
