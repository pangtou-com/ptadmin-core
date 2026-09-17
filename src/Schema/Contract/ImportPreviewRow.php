<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportPreviewRow 描述导入预览行。
 */
final class ImportPreviewRow extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'row' => 'int',
        'values' => 'array',
        'data' => 'array',
        'valid' => 'bool',
        'issues' => [ImportIssue::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'values' => ['omit_empty' => true],
        'data' => ['omit_empty' => true],
        'issues' => ['omit_empty' => true],
    ];

    /** Row 问题或预览数据对应的来源行号。 */
    public int $row = 0;

    /** Values 当前行按来源列读取的原始值集合。 */
    public array $values = [];

    /** Data 按 Schema 字段映射后的业务数据。 */
    public array $data = [];

    /** Valid 表示当前行是否通过映射和字段校验。 */
    public bool $valid = false;

    /** Issues 当前对象关联的结构化问题列表。 */
    public array $issues = [];
}
