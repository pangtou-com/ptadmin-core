<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * RebuildTreeResult 描述重建树索引结果。
 */
final class RebuildTreeResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'count' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Count 参与重建的节点数量。 */
    public int $count = 0;
}
