<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportColumnMapping 描述一个列到字段的映射。
 */
final class ImportColumnMapping extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'column_name' => 'string',
        'field_name' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'field_name' => ['omit_empty' => true],
    ];

    /** ColumnName 导入来源中的列名称。 */
    public string $column_name = '';

    /** FieldName 与来源列绑定的 Schema 字段名称。 */
    public string $field_name = '';
}
