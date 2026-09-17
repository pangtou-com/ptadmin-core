<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * Filter 查询结构。
 */
final class Filter extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'field' => 'string',
        'operator' => 'string',
        'value' => 'mixed',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'value' => ['omit_empty' => true],
    ];

    /** Field 查询字段。 */
    public string $field = '';

    /** Operator 查询符号。 */
    public string $operator = '';

    /** Value 查询值。 */
    public mixed $value = null;
}
