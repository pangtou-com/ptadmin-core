<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CandidateGroup 描述候选项分组的稳定值和展示名称。
 */
final class CandidateGroup extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'value' => 'mixed',
        'label' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Value 字段、候选项或导入单元格的实际值。 */
    public mixed $value = null;

    /** Label 字段值或候选项的展示文本。 */
    public string $label = '';
}
