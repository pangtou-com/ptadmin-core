<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SaveDraftSchemaResult 描述保存草稿 schema 的结果。
 */
final class SaveDraftSchemaResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'checksum' => 'string',
        'changed' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 保存后的版本信息。 */
    public ?VersionInfo $version = null;

    /** Checksum 保存后生成的草稿校验值。 */
    public string $checksum = '';

    /** Changed 本次保存是否产生实际变更。 */
    public bool $changed = false;
}
