<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CheckDraftResult 描述草稿检查结果。
 */
final class CheckDraftResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'validation_result' => ValidationResult::class,
        'migration_plan' => MigrationPlan::class,
        'change_summary' => ChangeSummary::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 被检查的版本信息。 */
    public ?VersionInfo $version = null;

    /** ValidationResult 结构校验结果。 */
    public ?ValidationResult $validation_result = null;

    /** MigrationPlan 迁移计划摘要。 */
    public ?MigrationPlan $migration_plan = null;

    /** ChangeSummary 变更摘要。 */
    public ?ChangeSummary $change_summary = null;
}
