<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListResult 描述列表查询结果。
 */
final class ListResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'results' => 'array',
        'total' => 'int',
        'page' => 'int',
        'limit' => 'int',
        'meta' => 'array',
        'summary' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'meta' => ['omit_empty' => true],
        'summary' => ['omit_empty' => true],
    ];

    /** Results 当前页记录列表。 */
    public array $results = [];

    /** Total 符合条件的总记录数。 */
    public int $total = 0;

    /** Page 当前页码。 */
    public int $page = 0;

    /** Limit 当前分页大小。 */
    public int $limit = 0;

    /** Meta 附加元信息。 */
    public array $meta = [];

    /** Summary 列表汇总信息。 */
    public array $summary = [];
}
