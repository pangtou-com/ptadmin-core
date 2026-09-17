<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * Sort 排序结构。
 */
final class Sort extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'field' => 'string',
        'order' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Field 排序字段。 */
    public string $field = '';

    /** Order 排序方式，默认为asc，例如 asc 或 desc。 */
    public string $order = '';
}
