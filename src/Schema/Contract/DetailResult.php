<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * DetailResult 描述详情查询结果。
 */
final class DetailResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'item' => 'array',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'meta' => ['omit_empty' => true],
    ];

    /** Item 命中的记录详情。 */
    public array $item = [];

    /** Meta 附加元信息。 */
    public array $meta = [];
}
