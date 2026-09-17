<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ChangeSummary 描述一次发布相对激活版本的变化摘要。
 */
final class ChangeSummary extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'created_fields' => 'array',
        'updated_fields' => [FieldChange::class],
        'removed_fields' => 'array',
        'capability_changes' => 'array',
        'action_changes' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'created_fields' => ['omit_empty' => true],
        'updated_fields' => ['omit_empty' => true],
        'removed_fields' => ['omit_empty' => true],
        'capability_changes' => ['omit_empty' => true],
        'action_changes' => ['omit_empty' => true],
    ];

    /** CreatedFields 新增字段列表。 */
    public array $created_fields = [];

    /** UpdatedFields 已更新字段列表。 */
    public array $updated_fields = [];

    /** RemovedFields 已删除字段列表。 */
    public array $removed_fields = [];

    /** CapabilityChanges 能力开关变化摘要。 */
    public array $capability_changes = [];

    /** ActionChanges 动作变化摘要。 */
    public array $action_changes = [];
}
