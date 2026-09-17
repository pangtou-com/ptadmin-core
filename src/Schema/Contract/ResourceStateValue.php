<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ResourceStateValue 描述资源状态值的系统语义。
 */
final class ResourceStateValue extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'kind' => 'string',
        'label' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'kind' => ['omit_empty' => true],
        'label' => ['omit_empty' => true],
    ];

    /** Kind 系统语义。 */
    public string $kind = '';

    /** Label 展示文本。 */
    public string $label = '';
}
