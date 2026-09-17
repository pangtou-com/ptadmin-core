<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListVersionsResult 描述版本列表查询结果。
 */
final class ListVersionsResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'results' => [VersionInfo::class],
        'total' => 'int',
        'page' => 'int',
        'limit' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Results 当前页版本列表。 */
    public array $results = [];

    /** Total 符合条件的总记录数。 */
    public int $total = 0;

    /** Page 当前页码。 */
    public int $page = 0;

    /** Limit 当前分页大小。 */
    public int $limit = 0;
}
