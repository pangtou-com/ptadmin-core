<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * PublishTaskInfo 描述发布任务的公开状态信息。
 */
final class PublishTaskInfo extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'int',
        'resource' => ResourceKey::class,
        'version' => VersionRef::class,
        'status' => 'string',
        'phase' => 'string',
        'last_error_code' => 'string',
        'last_error_message' => 'string',
        'started_at' => 'mixed',
        'finished_at' => 'mixed',
        'migration_result' => 'array',
        'projection_result' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'last_error_code' => ['omit_empty' => true],
        'last_error_message' => ['omit_empty' => true],
        'started_at' => ['nullable' => true, 'omit_empty' => true],
        'finished_at' => ['nullable' => true, 'omit_empty' => true],
        'migration_result' => ['omit_empty' => true],
        'projection_result' => ['omit_empty' => true],
    ];

    /** ID 发布任务唯一标识。 */
    public int $id = 0;

    /** Resource 任务对应的资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 任务对应的版本引用。 */
    public ?VersionRef $version = null;

    /** Status 任务状态。 */
    public string $status = '';

    /** Phase 任务当前阶段。 */
    public string $phase = '';

    /** LastErrorCode 最近一次失败错误码。 */
    public string $last_error_code = '';

    /** LastErrorMessage 最近一次失败错误信息。 */
    public string $last_error_message = '';

    /** StartedAt 任务开始时间。 */
    public mixed $started_at = null;

    /** FinishedAt 任务完成时间。 */
    public mixed $finished_at = null;

    /** MigrationResult 迁移阶段的稳定事实摘要。 */
    public array $migration_result = [];

    /** ProjectionResult 字段投影阶段的稳定事实摘要。 */
    public array $projection_result = [];
}
