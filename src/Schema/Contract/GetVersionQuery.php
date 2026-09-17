<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetVersionQuery 描述单个版本查询条件。
 */
final class GetVersionQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionRef::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 目标版本引用。 */
    public ?VersionRef $version = null;
}
