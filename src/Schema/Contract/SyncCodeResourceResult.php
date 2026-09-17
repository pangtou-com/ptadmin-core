<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SyncCodeResourceResult 描述 code resource 同步结果。
 */
final class SyncCodeResourceResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'changed' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 同步后的版本信息；内容未变化时可直接返回当前活动版本。 */
    public ?VersionInfo $version = null;

    /** Changed 本次同步是否产生变更。 */
    public bool $changed = false;
}
