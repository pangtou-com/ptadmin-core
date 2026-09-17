<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * MigrationStep 描述迁移计划中的一步。
 */
final class MigrationStep extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'key' => 'string',
        'target' => 'string',
        'risk' => 'string',
        'changes' => 'array',
        'destructive' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'changes' => ['omit_empty' => true],
    ];

    /** Key 步骤稳定键。 */
    public string $key = '';

    /** Target 步骤目标对象。 */
    public string $target = '';

    /** Risk 当前迁移步骤的风险等级。 */
    public string $risk = 'safe';

    /** Changes 触发当前迁移步骤的存储属性变化。 */
    public array $changes = [];

    /** Destructive 该步骤是否为破坏性变更。 */
    public bool $destructive = false;
}
