<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * FieldValue 描述字段的当前值、原始值和可选展示标签。
 */
final class FieldValue extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'name' => 'string',
        'value' => 'mixed',
        'raw' => 'mixed',
        'label' => 'mixed',
        'has_label' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Name 字段或列的稳定名称。 */
    public string $name = '';

    /** Value 字段、候选项或导入单元格的实际值。 */
    public mixed $value = null;

    /** Raw 未经显示转换的原始字段值。 */
    public mixed $raw = null;

    /** Label 字段值或候选项的展示文本。 */
    public mixed $label = null;

    /** HasLabel 表示当前字段值是否包含独立展示文本。 */
    public bool $has_label = false;
}
