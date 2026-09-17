<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * RelationCandidatesResult 描述关联候选项查询结果。
 */
final class RelationCandidatesResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'results' => [CandidateItem::class],
        'selected' => [CandidateItem::class],
        'total' => 'int',
        'page' => 'int',
        'limit' => 'int',
        'has_more' => 'bool',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'selected' => ['omit_empty' => true],
        'has_more' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
    ];

    /** Results 当前页候选项列表。 */
    public array $results = [];

    /** Selected 按已选值回补出的候选项集合。 */
    public array $selected = [];

    /** Total 符合条件的总候选项数量。 */
    public int $total = 0;

    /** Page 当前页码。 */
    public int $page = 0;

    /** Limit 当前分页大小。 */
    public int $limit = 0;

    /** HasMore 后续是否仍有更多候选项页。 */
    public bool $has_more = false;

    /** Meta 候选项查询附加元信息。 */
    public array $meta = [];
}
