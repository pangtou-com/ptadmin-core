<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListResourcesResult 描述资源列表查询结果。
 */
final class ListResourcesResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'results' => [ResourceInfo::class],
        'total' => 'int',
        'page' => 'int',
        'limit' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Results 当前页资源列表。 */
    public array $results = [];

    /** Total 符合条件的总记录数。 */
    public int $total = 0;

    /** Page 当前页码。 */
    public int $page = 0;

    /** Limit 当前分页大小。 */
    public int $limit = 0;
}
