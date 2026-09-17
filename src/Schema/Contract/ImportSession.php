<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportSession 描述一次导入检查后的会话状态。
 */
final class ImportSession extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'string',
        'resource' => ResourceKey::class,
        'source_ref' => 'string',
        'filename' => 'string',
        'mode' => 'string',
        'revision' => 'int',
        'status' => 'string',
        'can_commit' => 'bool',
        'sheet_name' => 'string',
        'header_row' => 'int',
        'data_start_row' => 'int',
        'total_rows' => 'int',
        'valid_rows' => 'int',
        'error_rows' => 'int',
        'columns' => [ImportColumn::class],
        'mapping' => [ImportColumnMapping::class],
        'missing_required_fields' => 'array',
        'unmatched_columns' => 'array',
        'warnings' => [ImportIssue::class],
        'errors' => [ImportIssue::class],
        'rows' => [ImportPreviewRow::class],
        'created_count' => 'int',
        'skipped_count' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'filename' => ['omit_empty' => true],
        'sheet_name' => ['omit_empty' => true],
        'columns' => ['omit_empty' => true],
        'mapping' => ['omit_empty' => true],
        'missing_required_fields' => ['omit_empty' => true],
        'unmatched_columns' => ['omit_empty' => true],
        'warnings' => ['omit_empty' => true],
        'errors' => ['omit_empty' => true],
        'rows' => ['omit_empty' => true],
        'created_count' => ['omit_empty' => true],
        'skipped_count' => ['omit_empty' => true],
    ];

    /** ID 当前对象的稳定标识。 */
    public string $id = '';

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** SourceRef 宿主文件存储返回的来源引用，Schema 包不直接读取本地路径。 */
    public string $source_ref = '';

    /** Filename 导入来源或导出结果使用的文件名。 */
    public string $filename = '';

    /** Mode 当前导入或写入执行模式。 */
    public string $mode = '';

    /** Revision 会话修订号，提交时用于阻止旧映射覆盖新状态。 */
    public int $revision = 0;

    /** Status 当前对象所处的生命周期状态。 */
    public string $status = '';

    /** CanCommit 表示当前导入检查结果是否允许进入提交阶段。 */
    public bool $can_commit = false;

    /** SheetName 当前解析的数据工作表名称。 */
    public string $sheet_name = '';

    /** HeaderRow 字段标题所在行号。 */
    public int $header_row = 0;

    /** DataStartRow 第一条业务数据所在行号。 */
    public int $data_start_row = 0;

    /** TotalRows 导入来源中识别出的数据总行数。 */
    public int $total_rows = 0;

    /** ValidRows 通过字段映射和校验的数据行数。 */
    public int $valid_rows = 0;

    /** ErrorRows 存在映射或校验错误的数据行数。 */
    public int $error_rows = 0;

    /** Columns 从导入来源识别出的列结构。 */
    public array $columns = [];

    /** Mapping 导入列与 Schema 字段之间的映射。 */
    public array $mapping = [];

    /** MissingRequiredFields 尚未映射的必填 Schema 字段。 */
    public array $missing_required_fields = [];

    /** UnmatchedColumns 未能匹配 Schema 字段的来源列。 */
    public array $unmatched_columns = [];

    /** Warnings 不阻止提交但需要调用方确认的问题。 */
    public array $warnings = [];

    /** Errors 阻止当前操作继续执行的问题。 */
    public array $errors = [];

    /** Rows 当前批次或预览包含的数据行。 */
    public array $rows = [];

    /** CreatedCount 本次提交成功创建的记录数量。 */
    public int $created_count = 0;

    /** SkippedCount 本次提交因策略或错误跳过的记录数量。 */
    public int $skipped_count = 0;
}
