<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * RebuildFieldsProjectionResult 描述字段投影重建结果。
 */
final class RebuildFieldsProjectionResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'count' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 重建所依据的版本信息。 */
    public ?VersionInfo $version = null;

    /** Count 重建后落盘的字段数量。 */
    public int $count = 0;
}
