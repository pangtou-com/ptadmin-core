<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportIssue 描述导入检查中的错误或告警。
 */
final class ImportIssue extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'code' => 'string',
        'message' => 'string',
        'row' => 'int',
        'column_name' => 'string',
        'field_name' => 'string',
        'value' => 'mixed',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'row' => ['omit_empty' => true],
        'column_name' => ['omit_empty' => true],
        'field_name' => ['omit_empty' => true],
        'value' => ['omit_empty' => true],
    ];

    /** Code 可供调用方稳定判断的问题编码。 */
    public string $code = '';

    /** Message 面向调用方展示的问题说明。 */
    public string $message = '';

    /** Row 问题或预览数据对应的来源行号。 */
    public int $row = 0;

    /** ColumnName 导入来源中的列名称。 */
    public string $column_name = '';

    /** FieldName 与来源列绑定的 Schema 字段名称。 */
    public string $field_name = '';

    /** Value 字段、候选项或导入单元格的实际值。 */
    public mixed $value = null;
}
