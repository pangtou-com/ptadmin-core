<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * UpdateResult 描述更新记录结果。
 */
final class UpdateResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'int',
        'item' => 'array',
        'changed' => 'array',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'item' => ['omit_empty' => true],
        'changed' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
    ];

    /** ID 被更新记录主键值。 */
    public int $id = 0;

    /** Item 可选的回填记录详情。 */
    public array $item = [];

    /** Changed 实际发生变化的字段列表。 */
    public array $changed = [];

    /** Meta 附加元信息。 */
    public array $meta = [];
}
