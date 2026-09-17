<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CommitImportResult 描述导入提交结果。
 */
final class CommitImportResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'session_id' => 'string',
        'resource' => ResourceKey::class,
        'created_count' => 'int',
        'skipped_count' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** SessionID 导入会话的稳定标识。 */
    public string $session_id = '';

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** CreatedCount 本次提交成功创建的记录数量。 */
    public int $created_count = 0;

    /** SkippedCount 本次提交因策略或错误跳过的记录数量。 */
    public int $skipped_count = 0;
}
