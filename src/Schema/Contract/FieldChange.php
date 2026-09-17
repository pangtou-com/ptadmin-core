<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * FieldChange 描述单个字段的变更摘要。
 */
final class FieldChange extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'name' => 'string',
        'changes' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'changes' => ['omit_empty' => true],
    ];

    /** Name 字段名。 */
    public string $name = '';

    /** Changes 该字段发生的变化项集合。 */
    public array $changes = [];
}
