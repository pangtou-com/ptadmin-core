<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListVersionsQuery 描述版本列表查询条件。
 */
final class ListVersionsQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'page' => 'int',
        'limit' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'page' => ['omit_empty' => true],
        'limit' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Page 页码，从 1 开始。 */
    public int $page = 0;

    /** Limit 每页条数。 */
    public int $limit = 0;
}
