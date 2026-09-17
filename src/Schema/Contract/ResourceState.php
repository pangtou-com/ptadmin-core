<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ResourceState 描述资源当前激活版本投影出的状态配置。
 */
final class ResourceState extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'field' => 'string',
        'default_value' => 'mixed',
        'values' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'field' => ['omit_empty' => true],
        'default_value' => ['omit_empty' => true],
        'values' => ['omit_empty' => true],
    ];

    /** Field 保存业务状态的字段名。 */
    public string $field = '';

    /** DefaultValue 普通提交时使用的默认状态值。 */
    public mixed $default_value = null;

    /** Values 状态值到系统语义的映射。 */
    public array $values = [];
}
