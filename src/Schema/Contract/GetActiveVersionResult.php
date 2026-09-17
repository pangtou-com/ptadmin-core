<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetActiveVersionResult 描述激活版本查询结果。
 */
final class GetActiveVersionResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'schema' => \PTAdmin\Schema\Definition\Schema::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 当前激活版本信息。 */
    public ?VersionInfo $version = null;

    /** Schema 当前激活版本的 schema。 */
    public ?\PTAdmin\Schema\Definition\Schema $schema = null;
}
