<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ResourceCapabilities 描述资源当前激活版本投影出的能力开关。
 */
final class ResourceCapabilities extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'allow_import' => 'bool',
        'allow_export' => 'bool',
        'allow_duplicate' => 'bool',
        'allow_print' => 'bool',
        'allow_recycle' => 'bool',
        'allow_draft' => 'bool',
        'track_mode' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'allow_import' => ['omit_empty' => true],
        'allow_export' => ['omit_empty' => true],
        'allow_duplicate' => ['omit_empty' => true],
        'allow_print' => ['omit_empty' => true],
        'allow_recycle' => ['omit_empty' => true],
        'allow_draft' => ['omit_empty' => true],
        'track_mode' => ['omit_empty' => true],
    ];

    /** AllowImport 是否允许导入。 */
    public bool $allow_import = false;

    /** AllowExport 是否允许导出。 */
    public bool $allow_export = false;

    /** AllowDuplicate 是否允许复制。 */
    public bool $allow_duplicate = false;

    /** AllowPrint 是否允许打印。 */
    public bool $allow_print = false;

    /** AllowRecycle 是否启用回收站能力。 */
    public bool $allow_recycle = false;

    /** AllowDraft 是否允许草稿模式写入。 */
    public bool $allow_draft = false;

    /** TrackMode 审计追踪模式。 */
    public string $track_mode = '';
}
