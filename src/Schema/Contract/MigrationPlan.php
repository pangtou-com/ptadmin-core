<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * MigrationPlan 描述发布前评估出的迁移计划。
 */
final class MigrationPlan extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'required' => 'bool',
        'risk' => 'string',
        'destructive' => 'bool',
        'steps' => [MigrationStep::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'steps' => ['omit_empty' => true],
    ];

    /** Required 本次发布是否需要迁移。 */
    public bool $required = false;

    /** Risk 计划中最高的迁移风险等级。 */
    public string $risk = 'safe';

    /** Destructive 计划中是否存在破坏性变更。 */
    public bool $destructive = false;

    /** Steps 迁移步骤列表。 */
    public array $steps = [];
}
